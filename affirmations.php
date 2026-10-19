<?php
/*
 * ElanCité Product AI — affirmations déduites de la base, à confirmer par le PO
 * (réservée à l'administrateur connecté via admin.php ; rien n'est écrit dans Notion).
 *
 * Claude propose, pour une page choisie, des affirmations liées chacune à un extrait mot pour mot de la page ; le PO pose
 * Confirmée / Infirmée / À préciser. Les verdicts sont conservés dans data/affirmations.php. À chaque affichage, l'extrait
 * est recherché dans la copie de la base : introuvable = « à revoir » (voir lib/affirmations.php). Même suivi d'avancement
 * que les autres pages (lib/progress.php) sous le nom « affirmgen » : il doit différer du nom du stockage « affirmations »,
 * sinon le verrou du suivi bloquerait antoine_update (même fichier de verrou).
 */
define('ANTOINE', 1);
require __DIR__ . '/lib/core.php';
require __DIR__ . '/lib/notion.php';
require __DIR__ . '/lib/claude.php';
require __DIR__ . '/lib/prompts.php';
require __DIR__ . '/lib/affirmations.php';
require __DIR__ . '/lib/progress.php';

session_start();
header('X-Robots-Tag: noindex, nofollow');
header('Cache-Control: no-store');

$action = (string) ($_GET['action'] ?? '');
$logged = !empty($_SESSION['antoine_admin']);
$csrf = (string) ($_SESSION['csrf'] ?? '');

function h($s) { return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8'); }

if (!$logged) {
    if ($action !== '') progress_json(['error' => 'Session expirée : reconnectez-vous via admin.php.'], 401);
    header('Location: admin.php');
    exit;
}

function aff_steps($refresh) {
    return [['controles', 'Contrôles préalables'],
            ['lecture', $refresh ? 'Lecture de la base dans Notion' : 'Lecture de la base (copie en cache)'],
            ['preparation', 'Préparation du contexte'],
            ['claude', 'Déduction des affirmations par Claude'],
            ['reponse', 'Lecture de la réponse'],
            ['verification', 'Vérification des extraits et des doublons'],
            ['enregistrement', 'Enregistrement des affirmations']];
}

/** Génère les affirmations de la page $page en publiant chaque étape. Renvoie [succès, message]. */
function aff_run($c, $refresh, $page, RunProgress $P) {
    $step = 'controles';
    try {
        $P->start('controles');
        if (!$c['anthropic_key'] || strpos($c['anthropic_key'], 'REMPLACER') !== false) throw new AntoineError('antoine_config', "La clé d'API Claude n'est pas configurée.", 500);
        $month = gmdate('Y-m');
        $usage = antoine_read('usage', []);
        if ((float) $c['monthly_budget'] > 0 && (float) ($usage[$month]['total'] ?? 0) >= (float) $c['monthly_budget']) {
            throw new AntoineError('antoine_budget', "Le budget mensuel de l'outil est atteint.", 429);
        }
        $P->done('controles', "Clé d'API présente, budget du mois non atteint, aucun autre traitement en cours.", 3);

        $step = 'lecture'; $P->start('lecture', $refresh ? 'Interrogation de Notion…' : 'Lecture de la copie en cache…');
        $kb = antoine_ia_load_kb($refresh);
        $kbText = antoine_ia_kb_text($kb, true);
        if (!$kbText['used_count']) throw new AntoineError('antoine_no_pages', "Aucune page produit n'est disponible dans la base.", 400);
        $pages = antoine_claims_pages($kb);
        if (!isset($pages[$page])) throw new AntoineError('antoine_no_page', "La page « $page » n'existe pas (ou n'est plus utilisée) dans la base.", 400);
        $P->done('lecture', 'Page « ' . $page . ' » trouvée. Base lue le ' . date('d/m/Y à H:i', strtotime($kb['loaded_at'] ?? 'now')) . ' (UTC).', 15);

        $step = 'preparation'; $P->start('preparation');
        $known = array_values(array_filter(antoine_read('affirmations', ['items' => []])['items'] ?? [], function ($i) use ($page) { return $i['page'] === $page; }));
        $existing = array_map(function ($i) { return $i['affirmation']; }, array_filter($known, function ($i) { return empty($i['archived']); }));
        $built = antoine_ia_build_claims($kb['consignes'] ?? null, $page, array_values($existing));
        $source = (!empty($kb['consignes']) && !isset($kb['consignes']['erreur']) && !empty($kb['consignes']['affirmations'])) ? 'section « Affirmations » de la page Notion' : 'consignes par défaut du dépôt';
        $P->done('preparation', number_format(mb_strlen($kbText['text']), 0, ',', ' ') . " caractères de base transmis à Claude ; " . count($existing) . " affirmation(s) déjà connue(s) pour cette page ; consignes : $source.", 18);

        $step = 'claude'; $P->start('claude', 'Envoi à Claude, en attente de la première réponse…');
        $buf = ''; $t0 = microtime(true);
        $onText = function ($t) use (&$buf, $P) {
            $buf .= $t;
            $n = substr_count($buf, '"affirmation"');
            $P->tick('claude', number_format(mb_strlen($buf), 0, ',', ' ') . ' caractères reçus · ' . $n . ' affirmation(s) proposée(s)', 18 + 70 * min(1, $n / 25));
            return true; // le traitement va au bout même si la page est fermée
        };
        $stop = '';
        [$text, $cost, $err] = antoine_ia_claude_stream($kbText['text'], $built['instructions'], $built['messages'], $c, $onText, $stop);
        // La consommation est comptée même en cas d'erreur
        if ($cost > 0) antoine_update('usage', [], function ($u) use ($month, $cost) {
            if (!isset($u[$month])) $u[$month] = ['total' => 0, 'agencies' => []];
            $u[$month]['total'] = round($u[$month]['total'] + $cost, 6);
            $a = $u[$month]['agencies']['Admin (affirmations)'] ?? ['calls' => 0, 'cost' => 0];
            $u[$month]['agencies']['Admin (affirmations)'] = ['calls' => $a['calls'] + 1, 'cost' => round($a['cost'] + $cost, 6)];
            krsort($u);
            return array_slice($u, 0, 12, true);
        });
        if ($err) throw $err;
        if ($stop === 'max_tokens') {
            antoine_write('affirmations.brut', ['at' => gmdate('c'), 'stop_reason' => $stop, 'text' => $text]);
            throw new AntoineError('antoine_too_long', 'La réponse de Claude a été coupée : la limite de longueur (max_tokens, actuellement ' . (int) $c['max_tokens'] . ' dans config.php) est atteinte, réflexion comprise. Augmentez-la puis relancez (consommation comptée). Texte reçu conservé dans data/affirmations.brut.php.', 502);
        }
        $secs = max(1, round(microtime(true) - $t0));
        $P->done('claude', number_format(mb_strlen($text), 0, ',', ' ') . " caractères reçus en $secs s ; coût estimé " . number_format($cost, 2, ',', ' ') . ' $.', 88);

        $step = 'reponse'; $P->start('reponse');
        $data = antoine_ia_parse_json($text);
        if ($data === null) {
            antoine_write('affirmations.brut', ['at' => gmdate('c'), 'stop_reason' => $stop, 'text' => $text]);
            $n = mb_strlen($text);
            throw new AntoineError('antoine_invalid_json', "La réponse de Claude n'a pas pu être lue (consommation comptée) : JSON invalide. Fin de réponse annoncée : " . ($stop ?: 'inconnue') . ". Début : « " . trim(mb_substr($text, 0, 150)) . ' » — fin : « ' . trim(mb_substr($text, max(0, $n - 150))) . ' ». Texte complet conservé dans data/affirmations.brut.php.', 502);
        }
        $P->done('reponse', 'Réponse lue : ' . count(is_array($data['affirmations'] ?? null) ? $data['affirmations'] : []) . ' affirmation(s) proposée(s).', 90);

        $step = 'verification'; $P->start('verification', 'Contrôle des extraits et des doublons…');
        [$items, $info] = antoine_claims_check($data, $kb, $page, $known);
        $ec = array_sum($info['ecart']);
        $P->done('verification', count($items) . ' affirmation(s) retenue(s), ' . $ec . ' écartée(s)' . ($ec ? ' (' . implode(' ; ', array_map(function ($k, $v) { return "$k : $v"; }, array_keys($info['ecart']), $info['ecart'])) . ')' : '') . '.', 95);

        $step = 'enregistrement'; $P->start('enregistrement');
        $added = antoine_claims_add($items);
        antoine_update('affirmations', ['items' => []], function ($d) use ($page, $added, $info, $cost, $c, $kb) {
            $d['runs'] = array_slice(array_merge([['at' => gmdate('c'), 'page' => $page, 'ajoutees' => $added, 'ecartees' => array_sum($info['ecart']), 'ecart' => $info['ecart'],
                'synthese' => $info['synthese'], 'cost' => round($cost, 4), 'model' => $c['model'], 'kb_loaded_at' => $kb['loaded_at'] ?? '']], $d['runs'] ?? []), 0, 30);
            $d['last_page'] = $page;
            return $d;
        });
        $P->done('enregistrement', 'Affirmations conservées sur le serveur ; consommation comptée sous « Admin (affirmations) ».', 100);
        $msg = "Terminé : $added nouvelle(s) affirmation(s) pour « $page ». Coût estimé : " . number_format($cost, 2, ',', ' ') . ' $.';
        $P->finish($msg);
        return [true, $msg];
    } catch (AntoineError $e) {
        $P->fail($step, $e->getMessage());
        return [false, $e->getMessage()];
    } catch (Throwable $e) {
        error_log('ElanCité Product AI (affirmations) : ' . $e->getMessage());
        $why = get_class($e) . ' : ' . $e->getMessage() . ' (' . basename($e->getFile()) . ':' . $e->getLine() . ')'; // page réservée à l'admin : la cause exacte est utile
        $P->fail($step, 'Erreur interne du serveur — ' . $why);
        return [false, 'Erreur interne du serveur — ' . $why];
    }
}

$c = antoine_config();

if ($action === 'run' && ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    $pageReq = trim((string) ($_POST['page'] ?? ''));
    [$P, $lock] = progress_start_run('affirmgen', aff_steps(!empty($_POST['refresh'])), $csrf, 'affirmations');
    [$ok, $msg] = aff_run($c, !empty($_POST['refresh']), $pageReq, $P);
    progress_end_run($lock);
    progress_json(['ok' => $ok, 'message' => $msg]);
}
if ($action === 'progress') progress_poll('affirmgen');

// Verdicts, archivage (appel depuis la page, sans rechargement)
if ($action === 'set' && ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    session_write_close();
    if (!hash_equals($csrf, (string) ($_POST['csrf'] ?? ''))) progress_json(['ok' => false, 'error' => 'Requête refusée (jeton de sécurité invalide). Rechargez la page.'], 403);
    [$ok, $m] = antoine_claims_set((string) ($_POST['id'] ?? ''), (string) ($_POST['op'] ?? ''), (string) ($_POST['verdict'] ?? ''), (string) ($_POST['comment'] ?? ''));
    progress_json(['ok' => $ok, 'message' => $m], $ok ? 200 : 400);
}

// Sauvegarde : tout le fichier, en JSON
if ($action === 'export') {
    session_write_close();
    header('Content-Type: application/json; charset=utf-8');
    header('Content-Disposition: attachment; filename="affirmations-' . gmdate('Y-m-d') . '.json"');
    echo json_encode(antoine_read('affirmations', ['items' => []]), JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    exit;
}

// Relecture de la base Notion seule, sans appel à Claude
$msg = ''; $msgOk = true;
if (isset($_POST['reload']) && hash_equals($csrf, (string) ($_POST['csrf'] ?? ''))) {
    try { $kb0 = antoine_ia_load_kb(true); $msg = 'Base relue dans Notion (' . count($kb0['pages']) . ' pages produit).'; }
    catch (AntoineError $e) { $msg = $e->getMessage(); $msgOk = false; }
}

session_write_close();

/* ---------- Page ---------- */
$running = progress_running('affirmgen');
$cache = antoine_read('kb', null);
$kb = ($cache && isset($cache['kb'])) ? $cache['kb'] : null;
$store = antoine_read('affirmations', ['items' => []]);
$items = is_array($store['items'] ?? null) ? $store['items'] : [];
$rawDocs = $kb ? antoine_claims_pages($kb) : [];
$normDocs = $kb ? antoine_claims_normdocs($kb) : [];
$verdicts = antoine_claims_verdicts();

// État de chaque affirmation et compteurs
$cnt = ['tout' => 0, 'attente' => 0, 'confirmee' => 0, 'infirmee' => 0, 'a_preciser' => 0, 'revoir' => 0, 'archivees' => 0];
foreach ($items as $id => &$it) {
    $it['_state'] = $kb ? antoine_claim_state($it, $normDocs) : 'ok';
    if (!empty($it['archived'])) { $cnt['archivees']++; continue; }
    $cnt['tout']++;
    if ($it['_state'] !== 'ok') $cnt['revoir']++;
    if ($it['verdict'] === '') $cnt['attente']++; else $cnt[$it['verdict']]++;
}
unset($it);

$fp = (string) ($_GET['p'] ?? '');
$fv = (string) ($_GET['v'] ?? 'tout');
if (!isset($cnt[$fv])) $fv = 'tout';
$shown = array_filter($items, function ($it) use ($fp, $fv) {
    if ($fp !== '' && $it['page'] !== $fp) return false;
    $arch = !empty($it['archived']);
    if ($fv === 'archivees') return $arch;
    if ($arch) return false;
    if ($fv === 'tout') return true;
    if ($fv === 'attente') return $it['verdict'] === '';
    if ($fv === 'revoir') return $it['_state'] !== 'ok';
    return $it['verdict'] === $fv;
});
uasort($shown, function ($a, $b) { return [$a['page'], $a['created_at']] <=> [$b['page'], $b['created_at']]; });
$byPage = [];
foreach ($shown as $it) $byPage[$it['page']][] = $it;

$perPage = [];
foreach ($items as $it) if (empty($it['archived'])) $perPage[$it['page']] = ($perPage[$it['page']] ?? 0) + 1;
$sel = (string) ($store['last_page'] ?? '');
if (!isset($rawDocs[$sel])) { $sel = ''; foreach ($rawDocs as $n => $_) { $sel = $n; if ($n !== 'Marque & positionnement') break; } }
function link_to($p, $v) { return 'affirmations.php?' . http_build_query(array_filter(['p' => $p, 'v' => $v === 'tout' ? '' : $v])); }
$runs = $store['runs'] ?? [];
?><!DOCTYPE html>
<html lang="fr"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title>ElanCité Product AI — affirmations à confirmer</title>
<link rel="icon" href="favicon.ico" sizes="any">
<link rel="icon" href="favicon.svg" type="image/svg+xml">
<link rel="apple-touch-icon" href="apple-touch-icon.png">
<style>
body{font-family:"Segoe UI",Roboto,Helvetica,Arial,sans-serif;background:#EEF1F3;color:#22272B;margin:0}
header{background:#2E3439;color:#fff;padding:16px 24px;border-bottom:6px solid #C8102E}
header h1{margin:0;font-size:20px}
header a{color:#fff;font-size:14px}
main{max-width:1100px;margin:0 auto;padding:24px}
section{background:#fff;border:1px solid #D3D9DE;border-radius:6px;padding:18px 20px;margin-bottom:18px}
h2{font-size:17px;margin:0 0 12px}
.btn{border:0;border-radius:6px;padding:9px 16px;font-weight:700;background:#2E3439;color:#fff;cursor:pointer;font:inherit}
.btn:disabled{opacity:.5;cursor:default}
.msg{padding:10px 14px;border-left:4px solid #2E7D4F;background:#fff;margin-bottom:18px}
.msg.err{border-left-color:#B3261E}
.hint{color:#5E6A72;font-size:13px}
.warn{color:#B3261E;font-size:12px;font-weight:700}
.ref{color:#5E6A72;font-size:13px;margin-top:2px}
.tag{display:inline-block;font-size:12px;font-weight:700;border-radius:4px;padding:1px 7px;background:#EEF1F3;margin-right:6px}
.tag.forte{background:#F8D7DA}.tag.moyenne{background:#FFE9C7}
.wrap{overflow-x:auto}
table.map{border-collapse:collapse;width:100%;font-size:14px}
table.map th,table.map td{border:1px solid #D3D9DE;padding:6px 8px;text-align:center}
table.map th{background:#EEF1F3;font-size:13px}
table.map td.name,table.map th.name{text-align:left;white-space:nowrap}
table.map a{color:inherit;text-decoration:none}
td.na{background:#F4F6F8;color:#9AA5AD}
td.c0{background:#E4F2E9}td.c1{background:#FFF6D6}td.c2{background:#FFE0B8}td.c3{background:#F8C9C9}
td .n{font-weight:700;font-size:15px}
td .d{display:block;font-size:11px;color:#5E6A72}
.legend span{display:inline-block;padding:1px 8px;border:1px solid #D3D9DE;margin-right:6px;font-size:12px}
details{border-top:1px solid #D3D9DE;padding:8px 0}
details:first-of-type{border-top:0}
summary{cursor:pointer;font-weight:600}
.item{border-top:1px solid #EEF1F3;padding:8px 0 8px 12px}
.item:first-of-type{border-top:0}
.why{font-size:14px;margin-top:2px}
.cov{font-size:12px;color:#5E6A72;display:block}
td.t{text-align:left;font-size:13px;vertical-align:top}
td.gap{background:#F8C9C9;color:#B3261E;font-weight:700}
td.mu{background:#FFE9C7}
tr.sechead td{background:#EEF1F3;font-weight:700;text-align:left}
.ok{color:#2E7D4F}
.claim{border-top:1px solid #D3D9DE;padding:12px 0}
.claim:first-of-type{border-top:0}
.claim .aff{font-weight:600;font-size:15px}
.claim .meta{color:#5E6A72;font-size:13px;margin-top:3px}
.claim blockquote{margin:6px 0;padding:4px 10px;border-left:3px solid #D3D9DE;color:#3d464d;font-size:13px;white-space:pre-wrap}
.claim.revoir{background:#FFF6D6;margin:0 -10px;padding:12px 10px}
.claim.absente{background:#F4F6F8;margin:0 -10px;padding:12px 10px}
.vd label{display:inline-block;margin-right:12px;font-size:14px;cursor:pointer}
.claim textarea{width:100%;box-sizing:border-box;font:inherit;font-size:13px;margin-top:6px;border:1px solid #D3D9DE;border-radius:4px;padding:5px 7px}
.saved{color:#2E7D4F;font-size:12px;margin-left:6px}
.pill{display:inline-block;font-size:12px;font-weight:700;border-radius:10px;padding:1px 9px;margin-right:6px;background:#EEF1F3}
.pill.confirmee{background:#D7EEDF}.pill.infirmee{background:#F8D7DA}.pill.a_preciser{background:#FFE9C7}.pill.revoir{background:#FFE0B8}
.cnt{display:inline-block;margin:0 8px 6px 0;padding:6px 12px;border:1px solid #D3D9DE;border-radius:6px;background:#fff;text-decoration:none;color:inherit;font-size:14px}
.cnt b{font-size:17px}
.cnt.on{border-color:#2E3439;box-shadow:0 0 0 1px #2E3439}
select{font:inherit;padding:6px 8px;border:1px solid #D3D9DE;border-radius:6px;max-width:100%}
.small{font-size:12px}
.btn.sec{background:#5E6A72}
<?php echo progress_css(); ?>

</style></head><body>
<header><h1>ElanCité Product AI — affirmations à confirmer</h1> <a href="admin.php">← Administration</a></header>
<main>
<?php if ($msg !== ''): ?><div class="msg<?php echo $msgOk ? '' : ' err'; ?>"><?php echo h($msg); ?></div><?php endif; ?>

<section><h2>Générer des affirmations pour une page</h2>
  <p>Claude déduit de la page choisie des affirmations que vous confirmez, infirmez ou précisez. Chacune est liée à un extrait de la base : si cet extrait change plus tard, votre verdict est conservé mais marqué <strong>à revoir</strong>. Ce sont des <strong>pistes déduites</strong> : rien n'est écrit dans Notion, aucune page n'est validée par cet outil ; les corrections se traitent dans le projet Claude.</p>
  <form id="run-form" data-csrf="<?php echo h($csrf); ?>" data-running="<?php echo $running ? '1' : '0'; ?>">
    <label>Page : <select name="page">
      <?php foreach ($rawDocs as $n => $_): ?><option value="<?php echo h($n); ?>"<?php echo $n === $sel ? ' selected' : ''; ?>><?php echo h($n); ?><?php echo isset($perPage[$n]) ? ' (' . $perPage[$n] . ')' : ''; ?></option><?php endforeach; ?>
    </select></label><br><br>
    <label><input type="checkbox" name="refresh" value="1" checked> Relire la base Notion avant la génération</label><br><br>
    <button class="btn" id="run-btn" type="submit">Générer (25 affirmations au plus)</button>
    <span class="hint">Un appel à Claude, compté dans la consommation (« Admin (affirmations) »). Les affirmations déjà connues de la page ne sont pas reproposées.</span>
  </form>
  <?php echo progress_panel_html('Génération en cours…'); ?>
  <?php if ($runs): $r = $runs[0]; ?><p class="hint small">Dernière génération : « <?php echo h($r['page']); ?> », le <?php echo h(date('d/m/Y à H:i', strtotime($r['at']))); ?> (UTC), <?php echo (int) $r['ajoutees']; ?> ajoutée(s), <?php echo (int) $r['ecartees']; ?> écartée(s)<?php echo $r['ecart'] ? ' (' . h(implode(' ; ', array_map(function ($k, $v) { return "$k : $v"; }, array_keys($r['ecart']), $r['ecart']))) . ')' : ''; ?>, <?php echo number_format((float) $r['cost'], 2, ',', ' '); ?> $.<?php echo $r['synthese'] !== '' ? ' ' . h($r['synthese']) : ''; ?></p><?php endif; ?>
</section>

<section><h2>Mes verdicts</h2>
  <?php if (!$items): ?>
    <p>Aucune affirmation pour l'instant : générez-en pour une page ci-dessus.</p>
  <?php else: ?>
    <p class="hint">Copie de la base lue le <?php echo $kb ? h(date('d/m/Y à H:i', strtotime($kb['loaded_at'] ?? 'now'))) . ' (UTC)' : 'inconnue'; ?>. L'état « à revoir » est calculé d'après cette copie : relisez Notion pour tenir compte des dernières modifications.</p>
    <form method="post" style="margin-bottom:10px"><input type="hidden" name="csrf" value="<?php echo h($csrf); ?>"><button class="btn sec" name="reload" value="1">Relire la base Notion maintenant</button> <a class="hint" href="affirmations.php?action=export">Exporter les verdicts (JSON)</a></form>
    <div>
      <?php foreach (['tout' => 'Toutes', 'attente' => 'En attente', 'confirmee' => 'Confirmées', 'infirmee' => 'Infirmées', 'a_preciser' => 'À préciser', 'revoir' => 'À revoir', 'archivees' => 'Archivées'] as $k => $lbl): ?>
        <a class="cnt<?php echo $fv === $k ? ' on' : ''; ?>" href="<?php echo h(link_to($fp, $k)); ?>"><b><?php echo (int) $cnt[$k]; ?></b> <?php echo h($lbl); ?></a>
      <?php endforeach; ?>
    </div>
    <p><label>Page : <select onchange="location.href=this.value">
      <option value="<?php echo h(link_to('', $fv)); ?>">Toutes les pages</option>
      <?php foreach ($perPage as $n => $k): ?><option value="<?php echo h(link_to($n, $fv)); ?>"<?php echo $fp === $n ? ' selected' : ''; ?>><?php echo h($n); ?> (<?php echo (int) $k; ?>)</option><?php endforeach; ?>
    </select></label></p>
    <?php if (!$byPage): ?><p class="hint">Aucune affirmation ne correspond à ce filtre.</p><?php endif; ?>
    <?php foreach ($byPage as $pn => $list): ?>
      <h3 style="font-size:15px;margin:18px 0 4px"><?php echo h($pn); ?> <span class="hint">— <?php echo count($list); ?> affirmation(s)</span></h3>
      <?php foreach ($list as $it): $st = $it['_state']; $arch = !empty($it['archived']); ?>
        <div class="claim<?php echo $st === 'revoir' ? ' revoir' : ($st === 'page_absente' ? ' absente' : ''); ?>" data-id="<?php echo h($it['id']); ?>">
          <div class="aff"><?php if ($it['verdict'] !== ''): ?><span class="pill <?php echo h($it['verdict']); ?>"><?php echo h($verdicts[$it['verdict']] ?? $it['verdict']); ?></span><?php endif; ?>
            <?php if ($st === 'revoir'): ?><span class="pill revoir">À revoir</span><?php elseif ($st === 'page_absente'): ?><span class="pill">Page absente</span><?php endif; ?>
            <?php echo h($it['affirmation']); ?></div>
          <div class="meta"><?php echo h($it['reference']); ?><?php echo $it['verdict_at'] !== '' ? ' · verdict du ' . h(date('d/m/Y', strtotime($it['verdict_at']))) : ''; ?></div>
          <?php if ($st === 'ok'): ?>
            <blockquote><?php echo h($it['extrait']); ?></blockquote>
          <?php elseif ($st === 'revoir'): ?>
            <p class="warn">⚠ L'extrait n'est plus dans la page : le texte a été modifié ou supprimé depuis la génération<?php echo $it['verdict'] !== '' ? ' (votre verdict est conservé)' : ''; ?>.</p>
            <div class="meta">Avant (extrait de l'affirmation) :</div><blockquote><?php echo h($it['extrait']); ?></blockquote>
            <?php $cur = antoine_claim_current_section($it, $rawDocs); ?>
            <div class="meta">Après (section citée, état actuel de la base) :</div>
            <blockquote><?php echo $cur !== null ? h($cur) : '(section introuvable : elle a été renommée ou supprimée)'; ?></blockquote>
            <p class="hint small">Archivez cette affirmation, puis régénérez la page pour obtenir la formulation à jour.</p>
          <?php else: ?>
            <p class="warn">⚠ La page « <?php echo h($it['page']); ?> » n'existe plus dans la base (supprimée, obsolète ou renommée).</p>
            <blockquote><?php echo h($it['extrait']); ?></blockquote>
          <?php endif; ?>
          <?php if (!$arch): ?>
            <div class="vd">
              <?php foreach ($verdicts as $k => $lbl): ?><label><input type="radio" name="v-<?php echo h($it['id']); ?>" value="<?php echo h($k); ?>"<?php echo $it['verdict'] === $k ? ' checked' : ''; ?><?php echo $st !== 'ok' ? ' disabled' : ''; ?>> <?php echo h($lbl); ?></label><?php endforeach; ?>
              <?php if ($st === 'ok' && $it['verdict'] !== ''): ?><a href="#" class="clear small">effacer le verdict</a><?php endif; ?>
              <?php if ($st !== 'ok'): ?><span class="hint small">Verdict figé tant que l'extrait n'est pas retrouvé.</span><?php endif; ?>
              <span class="saved"></span>
            </div>
            <textarea rows="1" placeholder="Commentaire (facultatif, utile pour « À préciser » et « Infirmée »)"<?php echo $st !== 'ok' ? ' disabled' : ''; ?>><?php echo h($it['commentaire']); ?></textarea>
            <p style="margin:6px 0 0"><a href="#" class="archive small">Archiver</a></p>
          <?php else: ?>
            <?php if ($it['commentaire'] !== ''): ?><div class="meta">Commentaire : <?php echo h($it['commentaire']); ?></div><?php endif; ?>
            <p style="margin:6px 0 0"><a href="#" class="restore small">Restaurer</a></p>
          <?php endif; ?>
        </div>
      <?php endforeach; ?>
    <?php endforeach; ?>
  <?php endif; ?>
</section>
</main>
<script>
(function () {
  var csrf = <?php echo json_encode($csrf); ?>;
  function post(id, op, verdict, comment, box, done) {
    var b = new URLSearchParams(); b.set('csrf', csrf); b.set('id', id); b.set('op', op); b.set('verdict', verdict || ''); b.set('comment', comment || '');
    fetch('affirmations.php?action=set', { method: 'POST', credentials: 'same-origin', body: b })
      .then(function (r) { return r.json().catch(function () { return { ok: false, message: 'Réponse illisible.' }; }); })
      .then(function (j) {
        var s = box.querySelector('.saved');
        if (s) { s.textContent = j.ok ? 'Enregistré' : ('Erreur : ' + (j.message || j.error || '')); s.style.color = j.ok ? '' : '#B3261E'; }
        if (j.ok && done) done();
      })
      .catch(function () { var s = box.querySelector('.saved'); if (s) { s.textContent = 'Erreur réseau : non enregistré'; s.style.color = '#B3261E'; } });
  }
  document.querySelectorAll('.claim').forEach(function (box) {
    var id = box.getAttribute('data-id'), ta = box.querySelector('textarea');
    function current() { var r = box.querySelector('input[type=radio]:checked'); return r ? r.value : ''; }
    box.querySelectorAll('input[type=radio]').forEach(function (r) { r.addEventListener('change', function () { post(id, 'verdict', current(), ta ? ta.value : '', box, function () { location.reload(); }); }); });
    if (ta) ta.addEventListener('change', function () { if (current() !== '') post(id, 'verdict', current(), ta.value, box); else { var s = box.querySelector('.saved'); if (s) s.textContent = 'Choisissez d\'abord un verdict : le commentaire n\'est enregistré qu\'avec lui.'; } });
    var cl = box.querySelector('.clear'); if (cl) cl.addEventListener('click', function (e) { e.preventDefault(); post(id, 'verdict', '', '', box, function () { location.reload(); }); });
    var ar = box.querySelector('.archive'); if (ar) ar.addEventListener('click', function (e) { e.preventDefault(); post(id, 'archive', '', '', box, function () { location.reload(); }); });
    var rs = box.querySelector('.restore'); if (rs) rs.addEventListener('click', function (e) { e.preventDefault(); post(id, 'restore', '', '', box, function () { location.reload(); }); });
  });
})();
<?php echo progress_js(['endpoint' => 'affirmations.php', 'fields' => ['page'], 'running' => 'Génération en cours…', 'done' => 'Génération terminée', 'fail' => 'Génération en échec',
  'btn_running' => 'Génération en cours…', 'btn_again' => 'Générer (25 affirmations au plus)',
  'pct_hint' => "(estimation : l'étape « Déduction des affirmations par Claude » représente l'essentiel du temps)", 'busy' => 'Un traitement est déjà en cours : suivi de son avancement']); ?>
</script>
</body></html>
