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
require __DIR__ . '/lib/admin_ui.php';
require __DIR__ . '/lib/notion.php';
require __DIR__ . '/lib/claude.php';
require __DIR__ . '/lib/prompts.php';
require __DIR__ . '/lib/progress.php';

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

/** Étapes de l'analyse (le libellé de la lecture dépend de l'option « relire Notion »). */
function analyse_steps($refresh) {
    return [['controles', 'Contrôles préalables'],
            ['lecture', $refresh ? 'Lecture de la base dans Notion' : 'Lecture de la base (copie en cache)'],
            ['preparation', 'Préparation du contexte'],
            ['claude', 'Analyse par Claude'],
            ['reponse', 'Lecture de la réponse'],
            ['verification', 'Vérification des références et des extraits'],
            ['enregistrement', 'Enregistrement du rapport']];
}

/** Exécute l'analyse en publiant chaque étape. Renvoie [succès, message]. */
function analyse_run($c, $refresh, RunProgress $P) {
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
    [$P, $lock] = progress_start_run('analyse', analyse_steps(!empty($_POST['refresh'])), $csrf, 'analyse');
    [$ok, $msg] = analyse_run($c, !empty($_POST['refresh']), $P);
    progress_end_run($lock);
    analyse_json(['ok' => $ok, 'message' => $msg]);
}

/* ---------- Avancement : lu toutes les secondes par la page ---------- */
if ($action === 'progress') progress_poll('analyse');

session_write_close();

/* ---------- Page ---------- */
$running = progress_running('analyse');
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
<?php echo admin_head_links(); ?>
<style>
.item{border-top:1px solid var(--line);padding:10px 0}
.item:first-of-type{border-top:0}
.ref{color:var(--muted);font-size:13px;margin-top:2px}
.why{font-size:14px;margin-top:2px}
.warn{color:var(--red);font-size:12px;font-weight:700}
.okv{color:var(--green);font-size:12px;font-weight:700}
blockquote{margin:6px 0 0;padding:4px 10px;border-left:3px solid var(--line);font-size:14px}
.tag{display:inline-block;font-size:12px;font-weight:700;border-radius:4px;padding:1px 7px;background:var(--hover);margin-right:6px}
.tag.forte{background:var(--red-bg)}.tag.moyenne{background:var(--amber-soft)}
<?php echo progress_css(); ?>
</style></head><body>
<?php admin_shell_open('analyse', 'Analyse de la base', true); ?>
<?php if ($msg): ?><div class="msg<?php echo $ok ? '' : ' err'; ?>"><?php echo h($msg); ?></div><?php endif; ?>

<section><h2>Analyse de la base</h2>
  <p>Claude examine la base entière (pages « À valider » comprises, pages « Obsolète » exclues) et propose des questions pour affiner la connaissance produit, des incohérences, des idées de compléments et les points encore ouverts. Ce sont des <strong>pistes à vérifier</strong> : rien n'est écrit dans Notion, et les corrections se traitent ensuite dans le projet Claude.</p>
  <form id="run-form" data-csrf="<?php echo h($csrf); ?>" data-running="<?php echo $running ? '1' : '0'; ?>">
    <label><input type="checkbox" name="refresh" value="1" checked> Relire la base Notion avant l'analyse</label><br><br>
    <button class="btn" id="run-btn" type="submit">Lancer l'analyse</button>
    <span class="hint">Un appel à Claude, compté dans la consommation (« Admin (analyse) »).</span>
  </form>
  <?php echo progress_panel_html('Analyse en cours…'); ?>
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
<?php admin_shell_close(); ?>
<script>
<?php echo progress_js(['endpoint' => 'analyse.php', 'running' => 'Analyse en cours…', 'done' => 'Analyse terminée', 'fail' => 'Analyse en échec',
  'btn_running' => 'Analyse en cours…', 'btn_again' => "Relancer l'analyse",
  'pct_hint' => "(estimation : l'étape « Analyse par Claude » représente l'essentiel du temps)", 'busy' => 'Une analyse est déjà en cours : suivi de son avancement']); ?>
</script>
</body></html>
