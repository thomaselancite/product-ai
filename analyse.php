<?php
/*
 * ElanCité Product AI — analyse de la base de connaissance (réservée à l'administrateur connecté via admin.php).
 *
 * L'analyse s'exécute dans une requête POST (?action=run) qui écrit son avancement, étape par étape, dans
 * data/analyse.progress.php ; la page lit cet avancement toutes les secondes (?action=progress). Rien ne
 * dépend d'un flux continu : cela fonctionne même si l'hébergeur retient les réponses jusqu'à leur fin,
 * et une page rechargée ou rouverte en cours d'analyse retrouve son état.
 */
define('ANTOINE', 1);
require __DIR__ . '/lib/core.php';
require __DIR__ . '/lib/notion.php';
require __DIR__ . '/lib/claude.php';
require __DIR__ . '/lib/prompts.php';

session_start();
header('X-Robots-Tag: noindex, nofollow');
header('Cache-Control: no-store');

$action = (string) ($_GET['action'] ?? '');
$logged = !empty($_SESSION['antoine_admin']);
$csrf = (string) ($_SESSION['csrf'] ?? '');

function h($s) { return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8'); }
function analyse_json($data, $status = 200) {
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo antoine_json($data);
    exit;
}

if (!$logged) {
    if ($action !== '') analyse_json(['error' => 'Session expirée : reconnectez-vous via admin.php.'], 401);
    header('Location: admin.php');
    exit;
}

/** Empreinte du texte de la base tel que transmis à Claude (pages « À valider » comprises). */
function analyse_kb_hash($kb) { return sha1(antoine_ia_kb_text($kb, true)['text']); }

/** Vrai si une analyse est en cours (verrou tenu par la requête qui l'exécute). */
function analyse_running() {
    $lock = fopen(antoine_data_path('analyse.lock'), 'c');
    if (!$lock) return false;
    if (flock($lock, LOCK_EX | LOCK_NB)) { flock($lock, LOCK_UN); fclose($lock); return false; }
    fclose($lock);
    return true;
}

/** Écriture atomique : le lecteur ne voit jamais un fichier à moitié écrit. */
function analyse_progress_write($d) {
    $f = antoine_data_path('analyse.progress'); $tmp = antoine_data_path('analyse.progress.tmp');
    $raw = "<?php exit; ?>\n" . antoine_json($d);
    if (@file_put_contents($tmp, $raw) === false || !@rename($tmp, $f)) @file_put_contents($f, $raw, LOCK_EX);
}

/** Avancement de l'analyse : étapes, état de chacune, pourcentage estimé. */
class AnalyseProgress {
    public $d;
    private $last = 0.0;
    function __construct($refresh, $token) {
        $defs = [['controles', 'Contrôles préalables'],
                 ['lecture', $refresh ? 'Lecture de la base dans Notion' : 'Lecture de la base (copie en cache)'],
                 ['preparation', 'Préparation du contexte'],
                 ['claude', 'Analyse par Claude'],
                 ['reponse', 'Lecture de la réponse'],
                 ['verification', 'Vérification des références et des extraits'],
                 ['enregistrement', 'Enregistrement du rapport']];
        $steps = [];
        foreach ($defs as $s) $steps[] = ['id' => $s[0], 'label' => $s[1], 'state' => 'pending', 'detail' => '', 't0' => null, 't1' => null];
        $now = microtime(true);
        $this->d = ['run_id' => bin2hex(random_bytes(6)), 'token' => $token, 'status' => 'running', 'started' => $now, 'updated' => $now,
                    'percent' => 0, 'steps' => $steps, 'message' => '', 'error' => ''];
        $this->flush(true);
    }
    private function idx($id) { foreach ($this->d['steps'] as $i => $s) if ($s['id'] === $id) return $i; return -1; }
    function start($id, $detail = '') {
        $i = $this->idx($id); if ($i < 0) return;
        $this->d['steps'][$i]['state'] = 'running'; $this->d['steps'][$i]['detail'] = $detail; $this->d['steps'][$i]['t0'] = microtime(true);
        $this->flush(true);
    }
    function done($id, $detail, $pct) {
        $i = $this->idx($id); if ($i < 0) return;
        $this->d['steps'][$i]['state'] = 'done'; $this->d['steps'][$i]['detail'] = $detail; $this->d['steps'][$i]['t1'] = microtime(true);
        $this->d['percent'] = $pct;
        $this->flush(true);
    }
    function fail($id, $msg) {
        $i = $this->idx($id);
        if ($i >= 0) { $this->d['steps'][$i]['state'] = 'error'; $this->d['steps'][$i]['detail'] = $msg; $this->d['steps'][$i]['t1'] = microtime(true); }
        $this->d['status'] = 'error'; $this->d['error'] = $msg;
        $this->flush(true);
    }
    function finish($msg) { $this->d['status'] = 'done'; $this->d['percent'] = 100; $this->d['message'] = $msg; $this->flush(true); }
    /** Mise à jour fréquente (réception de la réponse) : écrite au plus une fois par seconde. */
    function tick($id, $detail, $pct) {
        $i = $this->idx($id); if ($i < 0) return;
        $this->d['steps'][$i]['detail'] = $detail; $this->d['percent'] = round($pct, 1);
        $this->flush(false);
    }
    function flush($force) {
        $now = microtime(true);
        if (!$force && $now - $this->last < 1.0) return;
        $this->last = $now; $this->d['updated'] = $now;
        analyse_progress_write($this->d);
    }
}

/** Marque « interrompue » une analyse restée « en cours » alors que plus aucun processus ne la tient. */
function analyse_mark_interrupted($p) {
    $lock = fopen(antoine_data_path('analyse.lock'), 'c');
    if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) return $p; // une analyse tourne vraiment
    // Certains hébergements ne partagent pas le verrou entre requêtes : on exige aussi qu'aucune étape n'ait avancé depuis 150 s
    if (microtime(true) - (float) ($p['updated'] ?? 0) < 150) { flock($lock, LOCK_UN); fclose($lock); return $p; }
    foreach ($p['steps'] as $i => $s) if ($s['state'] === 'running') { $p['steps'][$i]['state'] = 'error'; $p['steps'][$i]['t1'] = microtime(true); }
    $p['status'] = 'error';
    $p['error'] = "Analyse interrompue avant la fin (erreur du serveur ou délai de l'hébergeur dépassé). La consommation éventuelle de Claude est comptée dans admin.php.";
    analyse_progress_write($p);
    flock($lock, LOCK_UN); fclose($lock);
    return $p;
}

/** Exécute l'analyse en publiant chaque étape. Renvoie [succès, message]. */
function analyse_run($c, $refresh, AnalyseProgress $P) {
    $step = 'controles';
    try {
        $P->start('controles');
        if (!$c['anthropic_key'] || strpos($c['anthropic_key'], 'REMPLACER') !== false) throw new AntoineError('antoine_config', "La clé d'API Claude n'est pas configurée.", 500);
        $month = gmdate('Y-m');
        $usage = antoine_read('usage', []);
        if ((float) $c['monthly_budget'] > 0 && (float) ($usage[$month]['total'] ?? 0) >= (float) $c['monthly_budget']) {
            throw new AntoineError('antoine_budget', "Le budget mensuel de l'outil est atteint.", 429);
        }
        $P->done('controles', "Clé d'API présente, budget du mois non atteint, aucune autre analyse en cours.", 3);

        $step = 'lecture'; $P->start('lecture', $refresh ? 'Interrogation de Notion…' : 'Lecture de la copie en cache…');
        $kb = antoine_ia_load_kb($refresh);
        $kbText = antoine_ia_kb_text($kb, true);
        if (!$kbText['used_count']) throw new AntoineError('antoine_no_pages', "Aucune page produit n'est disponible dans la base.", 400);
        $extra = count($kb['transverses'] ?? []) + (!empty($kb['brand']) ? 1 : 0);
        $P->done('lecture', $kbText['used_count'] . ' page(s) produit retenue(s)' . ($extra ? ", $extra autre(s) page(s) (marque et transverses)" : '') . '. Base lue le ' . date('d/m/Y à H:i', strtotime($kb['loaded_at'] ?? 'now')) . ' (UTC).', 15);

        $step = 'preparation'; $P->start('preparation');
        $built = antoine_ia_build_analysis($kb['consignes'] ?? null);
        $source = (!empty($kb['consignes']) && !isset($kb['consignes']['erreur']) && !empty($kb['consignes']['analyse'])) ? 'section « Analyse » de la page Notion' : 'consignes par défaut du dépôt';
        $P->done('preparation', number_format(mb_strlen($kbText['text']), 0, ',', ' ') . " caractères de base transmis à Claude ; consignes : $source.", 18);

        $step = 'claude'; $P->start('claude', 'Envoi à Claude, en attente de la première réponse…');
        $buf = ''; $t0 = microtime(true);
        $keys = ['"questions"', '"incoherences"', '"complements"', '"points_ouverts"'];
        $names = ['Synthèse', 'Questions', 'Incohérences', 'Compléments', 'Points ouverts'];
        $onText = function ($t) use (&$buf, $P, $keys, $names, $t0) {
            $buf .= $t;
            $found = 0; $lastPos = 0;
            foreach ($keys as $i => $k) { $p = strpos($buf, $k); if ($p === false) break; $found = $i + 1; $lastPos = $p; }
            $inSection = strlen($buf) - $lastPos;
            $frac = ($found + min(0.9, $inSection / 2500)) / 5;
            $detail = number_format(mb_strlen($buf), 0, ',', ' ') . ' caractères reçus';
            if ($found > 0) $detail .= ' · terminé : ' . implode(', ', array_slice($names, 0, $found));
            $detail .= ' · en cours : ' . $names[min($found, 4)];
            $P->tick('claude', $detail, 18 + 70 * $frac);
            return true; // l'analyse va au bout même si la page est fermée
        };
        $stop = '';
        [$text, $cost, $err] = antoine_ia_claude_stream($kbText['text'], $built['instructions'], $built['messages'], $c, $onText, $stop);
        // La consommation est comptée même en cas d'erreur
        if ($cost > 0) antoine_update('usage', [], function ($u) use ($month, $cost) {
            if (!isset($u[$month])) $u[$month] = ['total' => 0, 'agencies' => []];
            $u[$month]['total'] = round($u[$month]['total'] + $cost, 6);
            $a = $u[$month]['agencies']['Admin (analyse)'] ?? ['calls' => 0, 'cost' => 0];
            $u[$month]['agencies']['Admin (analyse)'] = ['calls' => $a['calls'] + 1, 'cost' => round($a['cost'] + $cost, 6)];
            krsort($u);
            return array_slice($u, 0, 12, true);
        });
        if ($err) throw $err;
        if ($stop === 'max_tokens') {
            antoine_write('analyse.brut', ['at' => gmdate('c'), 'stop_reason' => $stop, 'text' => $text]);
            throw new AntoineError('antoine_too_long', 'La réponse de Claude a été coupée : la limite de longueur (max_tokens, actuellement ' . (int) $c['max_tokens'] . ' dans config.php) est atteinte, réflexion comprise. Augmentez-la puis relancez (consommation comptée). Texte reçu conservé dans data/analyse.brut.php.', 502);
        }
        $secs = max(1, round(microtime(true) - $t0));
        $P->done('claude', number_format(mb_strlen($text), 0, ',', ' ') . " caractères reçus en $secs s ; coût estimé " . number_format($cost, 2, ',', ' ') . ' $.', 88);

        $step = 'reponse'; $P->start('reponse');
        $data = antoine_ia_parse_json($text);
        if ($data === null) {
            antoine_write('analyse.brut', ['at' => gmdate('c'), 'stop_reason' => $stop, 'text' => $text]);
            $n = mb_strlen($text);
            throw new AntoineError('antoine_invalid_json', "La réponse de Claude n'a pas pu être lue (consommation comptée) : JSON invalide. Fin de réponse annoncée : " . ($stop ?: 'inconnue') . ". Début : « " . trim(mb_substr($text, 0, 150)) . ' » — fin : « ' . trim(mb_substr($text, max(0, $n - 150))) . ' ». Texte complet conservé dans data/analyse.brut.php.', 502);
        }
        $P->done('reponse', 'Rapport lu : ' . count($data['questions'] ?? []) . ' question(s), ' . count($data['incoherences'] ?? []) . ' incohérence(s), '
            . count($data['complements'] ?? []) . ' complément(s), ' . count($data['points_ouverts'] ?? []) . ' point(s) ouvert(s).', 90);

        $step = 'verification'; $P->start('verification', 'Recherche de chaque référence et de chaque extrait dans la base…');
        [$report, $total, $warn] = antoine_ia_analysis_check($data, $kb);
        $P->done('verification', "$total remarque(s) contrôlée(s), $warn à vérifier avec soin (⚠).", 95);

        $step = 'enregistrement'; $P->start('enregistrement');
        antoine_write('analyse', [
            'generated_at' => gmdate('c'), 'kb_loaded_at' => $kb['loaded_at'] ?? '', 'kb_hash' => sha1($kbText['text']),
            'model' => $c['model'], 'cost' => round($cost, 4), 'pages' => $kbText['used_count'],
            'remarques' => $total, 'avertissements' => $warn, 'report' => $report,
        ]);
        $P->done('enregistrement', 'Rapport conservé sur le serveur ; consommation comptée sous « Admin (analyse) ».', 100);
        $msg = "Analyse terminée : $total remarque(s), dont $warn à vérifier avec soin. Coût estimé : " . number_format($cost, 2, ',', ' ') . ' $.';
        $P->finish($msg);
        return [true, $msg];
    } catch (AntoineError $e) {
        $P->fail($step, $e->getMessage());
        return [false, $e->getMessage()];
    } catch (Throwable $e) {
        error_log('ElanCité Product AI (analyse) : ' . $e->getMessage());
        $why = get_class($e) . ' : ' . $e->getMessage() . ' (' . basename($e->getFile()) . ':' . $e->getLine() . ')'; // page réservée à l'admin : la cause exacte est utile
        $P->fail($step, 'Erreur interne du serveur — ' . $why);
        return [false, 'Erreur interne du serveur — ' . $why];
    }
}

$c = antoine_config();

/* ---------- Lancement : requête longue, avancement écrit dans data/analyse.progress.php ---------- */
if ($action === 'run' && ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    if (!hash_equals($csrf, (string) ($_POST['csrf'] ?? ''))) analyse_json(['ok' => false, 'error' => 'Requête refusée (jeton de sécurité invalide). Rechargez la page.'], 403);
    @set_time_limit(300);
    ignore_user_abort(true); // l'analyse va au bout (et sa consommation est comptée) même si la page est fermée
    session_write_close();   // libère la session : la page peut interroger l'avancement pendant l'analyse
    $lock = fopen(antoine_data_path('analyse.lock'), 'c');
    if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) analyse_json(['ok' => false, 'busy' => true, 'error' => 'Une analyse est déjà en cours.'], 409);
    $token = preg_replace('/[^a-z0-9]/i', '', (string) ($_POST['token'] ?? ''));
    $P = new AnalyseProgress(!empty($_POST['refresh']), substr($token, 0, 32));
    // Si PHP s'arrête en pleine analyse (erreur fatale, délai, mémoire), la cause est inscrite dans le panneau
    register_shutdown_function(function () use ($P) {
        if (($P->d['status'] ?? '') !== 'running') return;
        $e = error_get_last(); $step = 'claude';
        foreach ($P->d['steps'] as $st) if ($st['state'] === 'running') $step = $st['id'];
        $why = $e ? ($e['message'] . ' (' . basename($e['file']) . ':' . $e['line'] . ')') : "arrêt sans erreur PHP";
        $why .= sprintf(' — après %d s, mémoire max %d Mo, max_execution_time %s s', microtime(true) - $P->d['started'], memory_get_peak_usage(true) / 1048576, ini_get('max_execution_time'));
        error_log('ElanCité Product AI (analyse) arrêt brutal : ' . $why);
        $P->fail($step, "Le script s'est arrêté pendant l'analyse : " . $why);
    });
    [$ok, $msg] = analyse_run($c, !empty($_POST['refresh']), $P);
    flock($lock, LOCK_UN); fclose($lock);
    analyse_json(['ok' => $ok, 'message' => $msg]);
}

/* ---------- Avancement : lu toutes les secondes par la page ---------- */
if ($action === 'progress') {
    session_write_close();
    $p = antoine_read('analyse.progress', null);
    if (is_array($p) && ($p['status'] ?? '') === 'running' && !analyse_running()) $p = analyse_mark_interrupted($p);
    analyse_json(['progress' => is_array($p) ? $p : null, 'server_time' => microtime(true)]);
}

session_write_close();

/* ---------- Page ---------- */
$running = analyse_running();
$lastProgress = antoine_read('analyse.progress', null);
$msg = ''; $ok = true;
if (!$running && is_array($lastProgress) && ($lastProgress['status'] ?? '') === 'done' && microtime(true) - (float) ($lastProgress['updated'] ?? 0) < 120) $msg = (string) ($lastProgress['message'] ?? '');

$saved = antoine_read('analyse', null);
$stale = false;
if ($saved) {
    $cache = antoine_read('kb', null);
    if ($cache && isset($cache['kb'])) $stale = analyse_kb_hash($cache['kb']) !== ($saved['kb_hash'] ?? '');
}

function warn_ref($v) { return $v === false ? ' <span class="warn" title="Page ou section introuvable dans la base">⚠ référence introuvable</span>' : ''; }
function ref_line($r) { return $r !== '' && $r !== null ? '<div class="ref">' . h($r) . '</div>' : ''; }
?><!DOCTYPE html>
<html lang="fr"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title>ElanCité Product AI — analyse de la base</title>
<link rel="icon" href="favicon.ico" sizes="any">
<link rel="icon" href="favicon.svg" type="image/svg+xml">
<link rel="apple-touch-icon" href="apple-touch-icon.png">
<style>
body{font-family:"Segoe UI",Roboto,Helvetica,Arial,sans-serif;background:#EEF1F3;color:#22272B;margin:0}
header{background:#2E3439;color:#fff;padding:16px 24px;border-bottom:6px solid #C8102E}
header h1{margin:0;font-size:20px}
header a{color:#fff;font-size:14px}
main{max-width:860px;margin:0 auto;padding:24px}
section{background:#fff;border:1px solid #D3D9DE;border-radius:6px;padding:18px 20px;margin-bottom:18px}
h2{font-size:17px;margin:0 0 12px}
.btn{border:0;border-radius:6px;padding:9px 16px;font-weight:700;background:#2E3439;color:#fff;cursor:pointer;font:inherit}
.btn:disabled{opacity:.5;cursor:default}
.msg{padding:10px 14px;border-left:4px solid #2E7D4F;background:#fff;margin-bottom:18px}
.msg.err{border-left-color:#B3261E}
.hint{color:#5E6A72;font-size:13px}
.item{border-top:1px solid #D3D9DE;padding:10px 0}
.item:first-of-type{border-top:0}
.ref{color:#5E6A72;font-size:13px;margin-top:2px}
.why{font-size:14px;margin-top:2px}
.warn{color:#B3261E;font-size:12px;font-weight:700}
.okv{color:#2E7D4F;font-size:12px;font-weight:700}
blockquote{margin:6px 0 0;padding:4px 10px;border-left:3px solid #D3D9DE;font-size:14px}
.tag{display:inline-block;font-size:12px;font-weight:700;border-radius:4px;padding:1px 7px;background:#EEF1F3;margin-right:6px}
.tag.forte{background:#F8D7DA}.tag.moyenne{background:#FFE9C7}
#live{display:none}
.bar{height:12px;background:#E3E8EC;border-radius:6px;overflow:hidden;margin:6px 0 4px}
.bar>div{height:100%;width:0;background:#2E7D4F;transition:width .6s ease}
.bar.err>div{background:#B3261E}
.steps{list-style:none;margin:14px 0 0;padding:0}
.steps li{display:flex;gap:10px;padding:7px 0;border-top:1px solid #EEF1F3;font-size:14px}
.steps li:first-child{border-top:0}
.ico{flex:0 0 22px;text-align:center;font-weight:700}
.ico.pending{color:#9AA5AD}.ico.done{color:#2E7D4F}.ico.error{color:#B3261E}
.spin{display:inline-block;width:13px;height:13px;border:2px solid #D3D9DE;border-top-color:#2E3439;border-radius:50%;animation:sp .8s linear infinite;vertical-align:-2px}
@keyframes sp{to{transform:rotate(360deg)}}
.steps .lbl{font-weight:600}
.steps .det{color:#5E6A72;font-size:13px;margin-top:1px}
.steps .dur{margin-left:auto;color:#5E6A72;font-size:13px;white-space:nowrap}
li.pending .lbl{color:#9AA5AD;font-weight:400}
</style></head><body>
<header><h1>ElanCité Product AI — analyse de la base</h1> <a href="admin.php">← Administration</a></header>
<main>
<?php if ($msg): ?><div class="msg<?php echo $ok ? '' : ' err'; ?>"><?php echo h($msg); ?></div><?php endif; ?>

<section><h2>Analyse de la base</h2>
  <p>Claude examine la base entière (pages « À valider » comprises, pages « Obsolète » exclues) et propose des questions pour affiner la connaissance produit, des incohérences, des idées de compléments et les points encore ouverts. Ce sont des <strong>pistes à vérifier</strong> : rien n'est écrit dans Notion, et les corrections se traitent ensuite dans le projet Claude.</p>
  <form id="run-form" data-csrf="<?php echo h($csrf); ?>" data-running="<?php echo $running ? '1' : '0'; ?>">
    <label><input type="checkbox" name="refresh" value="1" checked> Relire la base Notion avant l'analyse</label><br><br>
    <button class="btn" id="run-btn" type="submit">Lancer l'analyse</button>
    <span class="hint">Un appel à Claude, compté dans la consommation (« Admin (analyse) »).</span>
  </form>
  <div id="live" aria-live="polite">
    <div style="display:flex;justify-content:space-between;align-items:baseline;margin-top:6px"><strong id="live-title">Analyse en cours…</strong><span class="hint" id="live-time"></span></div>
    <div class="bar" id="live-bar"><div id="live-fill"></div></div>
    <div class="hint" id="live-pct"></div>
    <ul class="steps" id="live-steps"></ul>
    <div id="live-err" class="msg err" style="display:none;margin:12px 0 0"></div>
  </div>
</section>

<?php if (!$saved): ?>
  <section><p>Aucune analyse n'a encore été lancée.</p></section>
<?php else: $r = $saved['report']; ?>
  <section><h2>Dernier rapport</h2>
    <p class="hint">Généré le <?php echo h(date('d/m/Y à H:i', strtotime($saved['generated_at']))); ?> (UTC), sur <?php echo (int) $saved['pages']; ?> pages produit, modèle <?php echo h($saved['model']); ?>, coût estimé <?php echo number_format((float) $saved['cost'], 2, ',', ' '); ?> $. <?php echo (int) $saved['remarques']; ?> remarque(s), dont <?php echo (int) $saved['avertissements']; ?> à vérifier avec soin (⚠).</p>
    <?php if ($stale): ?><p class="warn">La base a été modifiée depuis ce rapport : relancez l'analyse pour tenir compte des changements.</p><?php endif; ?>
    <?php if ($r['synthese'] !== ''): ?><p><?php echo nl2br(h($r['synthese'])); ?></p><?php endif; ?>
    <p class="hint">⚠ signale une référence (page ou section) ou un extrait que le serveur n'a pas retrouvé dans la base : la remarque est peut-être inexacte. Les extraits sont comparés à la mise en forme près.</p>
  </section>

  <section><h2>Incohérences (<?php echo count($r['incoherences']); ?>)</h2>
  <?php if (!$r['incoherences']): ?><p class="hint">Aucune incohérence signalée.</p><?php endif; ?>
  <?php foreach ($r['incoherences'] as $i): $g = in_array($i['gravite'] ?? '', ['forte', 'moyenne', 'faible'], true) ? $i['gravite'] : 'faible'; ?>
    <div class="item"><span class="tag <?php echo h($g); ?>"><?php echo h($g); ?></span><?php echo h($i['description'] ?? ''); ?>
      <?php echo !empty($i['_verifiee']) ? ' <span class="okv">✓ extraits retrouvés</span>' : ' <span class="warn">⚠ à vérifier : au moins un extrait ou une référence est introuvable</span>'; ?>
      <?php foreach ($i['elements'] as $e): ?>
        <blockquote>« <?php echo h($e['extrait'] ?? ''); ?> »<?php echo ($e['_extrait_ok'] ?? true) === false ? ' <span class="warn">⚠ extrait introuvable</span>' : ''; ?>
          <?php echo ref_line($e['reference'] ?? ''); echo warn_ref($e['_ref_ok'] ?? null); ?></blockquote>
      <?php endforeach; ?></div>
  <?php endforeach; ?></section>

  <section><h2>Questions pour affiner (<?php echo count($r['questions']); ?>)</h2>
  <?php if (!$r['questions']): ?><p class="hint">Aucune question.</p><?php endif; ?>
  <?php foreach ($r['questions'] as $q): ?>
    <div class="item"><strong><?php echo h($q['question'] ?? ''); ?></strong>
      <div class="why"><?php echo h($q['pourquoi'] ?? ''); ?></div><?php echo ref_line($q['reference'] ?? ''); echo warn_ref($q['_ref_ok'] ?? null); ?></div>
  <?php endforeach; ?></section>

  <section><h2>Idées de compléments (<?php echo count($r['complements']); ?>)</h2>
  <?php if (!$r['complements']): ?><p class="hint">Aucune idée.</p><?php endif; ?>
  <?php foreach ($r['complements'] as $q): ?>
    <div class="item"><strong><?php echo h($q['idee'] ?? ''); ?></strong>
      <div class="why"><?php echo h($q['pourquoi'] ?? ''); ?></div><?php echo ref_line($q['reference'] ?? ''); echo warn_ref($q['_ref_ok'] ?? null); ?></div>
  <?php endforeach; ?></section>

  <section><h2>Points ouverts (<?php echo count($r['points_ouverts']); ?>)</h2>
  <?php if (!$r['points_ouverts']): ?><p class="hint">Aucun point ouvert.</p><?php endif; ?>
  <?php $labels = ['a_completer' => 'À compléter', 'point_a_lever' => "Point d'attention à lever", 'page_a_valider' => 'Page à valider'];
  foreach ($r['points_ouverts'] as $p): ?>
    <div class="item"><span class="tag"><?php echo h($labels[$p['type'] ?? ''] ?? 'Autre'); ?></span><?php echo h($p['element'] ?? ''); ?>
      <?php echo ref_line($p['reference'] ?? ''); echo warn_ref($p['_ref_ok'] ?? null); ?></div>
  <?php endforeach; ?></section>
<?php endif; ?>
</main>
<script>
(function () {
  var form = document.getElementById('run-form'), btn = document.getElementById('run-btn');
  var live = document.getElementById('live'), fill = document.getElementById('live-fill'), bar = document.getElementById('live-bar');
  var pct = document.getElementById('live-pct'), list = document.getElementById('live-steps'), title = document.getElementById('live-title');
  var timeEl = document.getElementById('live-time'), errBox = document.getElementById('live-err');
  var csrf = form.getAttribute('data-csrf'), token = null, timer = null, offset = 0, started = 0, finished = false;

  function esc(s) { var d = document.createElement('div'); d.textContent = s == null ? '' : String(s); return d.innerHTML; }
  function fmt(sec) { sec = Math.max(0, Math.round(sec)); return sec < 60 ? sec + ' s' : Math.floor(sec / 60) + ' min ' + ('0' + (sec % 60)).slice(-2) + ' s'; }
  function nowServer() { return (Date.now() + offset) / 1000; }

  function render(p) {
    live.style.display = 'block';
    started = p.started;
    fill.style.width = Math.max(2, p.percent) + '%';
    bar.className = 'bar' + (p.status === 'error' ? ' err' : '');
    pct.textContent = Math.round(p.percent) + ' % (estimation : l\'étape « Analyse par Claude » représente l\'essentiel du temps)';
    title.textContent = p.status === 'done' ? 'Analyse terminée' : (p.status === 'error' ? 'Analyse en échec' : 'Analyse en cours…');
    var html = '';
    p.steps.forEach(function (s) {
      var ico = s.state === 'done' ? '✓' : s.state === 'error' ? '✗' : s.state === 'running' ? '<span class="spin"></span>' : '○';
      var dur = '';
      if (s.t0 && s.t1) dur = fmt(s.t1 - s.t0);
      else if (s.t0) dur = fmt(nowServer() - s.t0);
      html += '<li class="' + s.state + '"><span class="ico ' + s.state + '">' + ico + '</span><div><div class="lbl">' + esc(s.label) + '</div>'
            + (s.detail ? '<div class="det">' + esc(s.detail) + '</div>' : '') + '</div><span class="dur">' + dur + '</span></li>';
    });
    list.innerHTML = html;
    if (p.status === 'error') { errBox.style.display = 'block'; errBox.textContent = p.error || 'Erreur.'; } else { errBox.style.display = 'none'; }
  }

  function tickClock() { if (started && !finished) timeEl.textContent = 'Temps écoulé : ' + fmt(nowServer() - started); }

  function poll() {
    return fetch('analyse.php?action=progress', { credentials: 'same-origin', cache: 'no-store' })
      .then(function (r) { if (r.status === 401) throw new Error('session'); return r.json(); })
      .then(function (j) {
        offset = j.server_time * 1000 - Date.now();
        var p = j.progress;
        if (!p || (token && p.token !== token)) return;       // pas encore la nôtre : on attend
        render(p);
        if (p.status !== 'running') {
          finished = true; clearInterval(timer);
          timeEl.textContent = 'Durée : ' + fmt(p.updated - p.started);
          if (p.status === 'done') setTimeout(function () { location.reload(); }, 1500);
          else { btn.disabled = false; btn.textContent = 'Relancer l\'analyse'; form.style.display = ''; }
        }
      })
      .catch(function (e) {
        if (e && e.message === 'session') { errBox.style.display = 'block'; errBox.textContent = 'Session expirée : reconnectez-vous via admin.php, puis relancez la page.'; live.style.display = 'block'; clearInterval(timer); }
      });
  }

  function watch() {
    finished = false;
    clearInterval(timer);
    timer = setInterval(function () { poll(); tickClock(); }, 1000);
    setInterval(tickClock, 1000);
    poll();
  }

  form.addEventListener('submit', function (ev) {
    ev.preventDefault();
    btn.disabled = true; btn.textContent = 'Analyse en cours…';
    errBox.style.display = 'none';
    token = Math.random().toString(36).slice(2) + Date.now().toString(36);
    var body = new URLSearchParams();
    body.set('csrf', csrf); body.set('token', token);
    if (form.elements['refresh'].checked) body.set('refresh', '1');
    live.style.display = 'block';
    title.textContent = 'Démarrage…';
    watch();
    // La requête dure aussi longtemps que l'analyse ; l'avancement vient du suivi ci-dessus.
    fetch('analyse.php?action=run', { method: 'POST', credentials: 'same-origin', body: body })
      .then(function (r) { return r.json().catch(function () { return null; }); })
      .then(function (j) {
        if (j && j.busy) { token = null; title.textContent = 'Une analyse est déjà en cours : suivi de son avancement'; return; }
        if (j && j.ok === false && j.error) { errBox.style.display = 'block'; errBox.textContent = j.error; }
        poll();
      })
      .catch(function () { /* coupure de la réponse (délai du serveur) : le suivi dit ce qu'il en est */ });
  });

  if (form.getAttribute('data-running') === '1') {   // page ouverte pendant une analyse : on reprend le suivi
    btn.disabled = true; btn.textContent = 'Analyse en cours…';
    watch();
  }
})();
</script>
</body></html>
