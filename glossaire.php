<?php
/*
 * ElanCité Product AI — cartographie du « Glossaire de traduction »
 * (réservée à l'administrateur connecté via admin.php, lecture seule : rien n'est écrit dans Notion).
 *
 * Partie 1, sans Claude : couverture du glossaire par section, par terme et par source, calculée à chaque affichage
 * d'après la copie de la base (lib/glossaire.php lit les tableaux de la page Notion).
 * Partie 2, un appel à Claude : termes utilisés dans les pages produit et marque mais absents du glossaire, contrôlés
 * par le serveur. Même mécanique de suivi que analyse.php et cartographie.php (lib/progress.php).
 */
define('ANTOINE', 1);
require __DIR__ . '/lib/core.php';
require __DIR__ . '/lib/admin_ui.php';
require __DIR__ . '/lib/notion.php';
require __DIR__ . '/lib/claude.php';
require __DIR__ . '/lib/prompts.php';
require __DIR__ . '/lib/glossaire.php';
require __DIR__ . '/lib/progress.php';

header('X-Robots-Tag: noindex, nofollow');
header('Cache-Control: no-store');

$action = (string) ($_GET['action'] ?? '');
$logged = antoine_admin_user() !== null;
$csrf = $logged ? antoine_csrf() : '';

function h($s) { return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8'); }

if (!$logged) {
    if ($action !== '') progress_json(['error' => 'Session expirée : reconnectez-vous via admin.php.'], 401);
    header('Location: admin.php');
    exit;
}

/** Empreinte du texte de la base tel que transmis à Claude (pages « À valider » comprises). */
function glo_kb_hash($kb) { return sha1(antoine_ia_kb_text($kb, true)['text']); }

function glo_steps($refresh) {
    return [['controles', 'Contrôles préalables'],
            ['lecture', $refresh ? 'Lecture de la base dans Notion' : 'Lecture de la base (copie en cache)'],
            ['preparation', 'Préparation du contexte'],
            ['claude', 'Recherche des termes absents par Claude'],
            ['reponse', 'Lecture de la réponse'],
            ['verification', 'Vérification des termes, extraits et références'],
            ['enregistrement', 'Enregistrement du résultat']];
}

/** Exécute la recherche des termes absents en publiant chaque étape. Renvoie [succès, message]. */
function glo_run($c, $refresh, RunProgress $P) {
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
        $glo = antoine_ia_glossary_find($kb);
        if (!$glo) throw new AntoineError('antoine_no_glossary', "La page « Glossaire de traduction » est introuvable parmi les sous-pages de l'accueil Notion.", 400);
        $P->done('lecture', $kbText['used_count'] . ' page(s) produit retenue(s) ; glossaire trouvé (« ' . $glo['nom'] . ' », statut ' . $glo['statut'] . '). Base lue le ' . date('d/m/Y à H:i', strtotime($kb['loaded_at'] ?? 'now')) . ' (UTC).', 15);

        $step = 'preparation'; $P->start('preparation');
        $built = antoine_ia_build_glossary($kb['consignes'] ?? null);
        $source = (!empty($kb['consignes']) && !isset($kb['consignes']['erreur']) && !empty($kb['consignes']['glossaire'])) ? 'section « Glossaire » de la page Notion' : 'consignes par défaut du dépôt';
        $P->done('preparation', number_format(mb_strlen($kbText['text']), 0, ',', ' ') . " caractères de base transmis à Claude ; consignes : $source.", 18);

        $step = 'claude'; $P->start('claude', 'Envoi à Claude, en attente de la première réponse…');
        $buf = ''; $t0 = microtime(true);
        $onText = function ($t) use (&$buf, $P) {
            $buf .= $t;
            $n = substr_count($buf, '"terme"');
            $frac = min(1, $n / 40); // au plus 40 termes demandés : estimation
            $P->tick('claude', number_format(mb_strlen($buf), 0, ',', ' ') . ' caractères reçus · ' . $n . ' terme(s) listé(s)', 18 + 70 * $frac);
            return true; // le traitement va au bout même si la page est fermée
        };
        $stop = '';
        [$text, $cost, $err] = antoine_ia_claude_stream($kbText['text'], $built['instructions'], $built['messages'], $c, $onText, $stop);
        // La consommation est comptée même en cas d'erreur
        if ($cost > 0) antoine_update('usage', [], function ($u) use ($month, $cost) {
            if (!isset($u[$month])) $u[$month] = ['total' => 0, 'agencies' => []];
            $u[$month]['total'] = round($u[$month]['total'] + $cost, 6);
            $a = $u[$month]['agencies']['Admin (glossaire)'] ?? ['calls' => 0, 'cost' => 0];
            $u[$month]['agencies']['Admin (glossaire)'] = ['calls' => $a['calls'] + 1, 'cost' => round($a['cost'] + $cost, 6)];
            krsort($u);
            return array_slice($u, 0, 12, true);
        });
        if ($err) throw $err;
        if ($stop === 'max_tokens') {
            antoine_write('glossaire.brut', ['at' => gmdate('c'), 'stop_reason' => $stop, 'text' => $text]);
            throw new AntoineError('antoine_too_long', 'La réponse de Claude a été coupée : la limite de longueur (max_tokens, actuellement ' . (int) $c['max_tokens'] . ' dans config.php) est atteinte, réflexion comprise. Augmentez-la puis relancez (consommation comptée). Texte reçu conservé dans data/glossaire.brut.php.', 502);
        }
        $secs = max(1, round(microtime(true) - $t0));
        $P->done('claude', number_format(mb_strlen($text), 0, ',', ' ') . " caractères reçus en $secs s ; coût estimé " . number_format($cost, 2, ',', ' ') . ' $.', 88);

        $step = 'reponse'; $P->start('reponse');
        $data = antoine_ia_parse_json($text);
        if ($data === null) {
            antoine_write('glossaire.brut', ['at' => gmdate('c'), 'stop_reason' => $stop, 'text' => $text]);
            $n = mb_strlen($text);
            throw new AntoineError('antoine_invalid_json', "La réponse de Claude n'a pas pu être lue (consommation comptée) : JSON invalide. Fin de réponse annoncée : " . ($stop ?: 'inconnue') . ". Début : « " . trim(mb_substr($text, 0, 150)) . ' » — fin : « ' . trim(mb_substr($text, max(0, $n - 150))) . ' ». Texte complet conservé dans data/glossaire.brut.php.', 502);
        }
        $P->done('reponse', 'Réponse lue : ' . count(is_array($data['termes'] ?? null) ? $data['termes'] : []) . ' terme(s) proposé(s).', 90);

        $step = 'verification'; $P->start('verification', 'Contrôle des termes, des extraits et des références…');
        [$res, $total, $warn] = antoine_ia_glossary_check($data, $kb);
        $P->done('verification', "$total terme(s) retenu(s), {$res['ecartes']} écarté(s) (déjà au glossaire, doublon ou cité seulement dans le glossaire), $warn à vérifier avec soin (⚠).", 95);

        $step = 'enregistrement'; $P->start('enregistrement');
        antoine_write('glossaire', [
            'generated_at' => gmdate('c'), 'kb_loaded_at' => $kb['loaded_at'] ?? '', 'kb_hash' => glo_kb_hash($kb),
            'model' => $c['model'], 'cost' => round($cost, 4), 'pages' => $kbText['used_count'],
            'termes' => $total, 'avertissements' => $warn, 'result' => $res,
        ]);
        $P->done('enregistrement', 'Résultat conservé sur le serveur ; consommation comptée sous « Admin (glossaire) ».', 100);
        $msg = "Recherche terminée : $total terme(s) absent(s) du glossaire, dont $warn à vérifier avec soin. Coût estimé : " . number_format($cost, 2, ',', ' ') . ' $.';
        $P->finish($msg);
        return [true, $msg];
    } catch (AntoineError $e) {
        $P->fail($step, $e->getMessage());
        return [false, $e->getMessage()];
    } catch (Throwable $e) {
        error_log('ElanCité Product AI (glossaire) : ' . $e->getMessage());
        $why = get_class($e) . ' : ' . $e->getMessage() . ' (' . basename($e->getFile()) . ':' . $e->getLine() . ')'; // page réservée à l'admin : la cause exacte est utile
        $P->fail($step, 'Erreur interne du serveur — ' . $why);
        return [false, 'Erreur interne du serveur — ' . $why];
    }
}

$c = antoine_config();

if ($action === 'run' && ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    [$P, $lock] = progress_start_run('glossaire', glo_steps(!empty($_POST['refresh'])), $csrf, 'glossaire');
    [$ok, $msg] = glo_run($c, !empty($_POST['refresh']), $P);
    progress_end_run($lock);
    progress_json(['ok' => $ok, 'message' => $msg]);
}
if ($action === 'progress') progress_poll('glossaire');

// Relecture de la base Notion seule (partie 1), sans appel à Claude
$msg = ''; $msgOk = true;
if (isset($_POST['reload']) && hash_equals($csrf, (string) ($_POST['csrf'] ?? ''))) {
    try { $kb0 = antoine_ia_load_kb(true); $msg = 'Base relue dans Notion (' . count($kb0['pages']) . ' pages produit).'; }
    catch (AntoineError $e) { $msg = $e->getMessage(); $msgOk = false; }
}

/* ---------- Page ---------- */
$running = progress_running('glossaire');
$cache = antoine_read('kb', null);
$kb = ($cache && isset($cache['kb'])) ? $cache['kb'] : null;
$glo = $kb ? antoine_ia_glossary_find($kb) : null;
$g = $glo ? antoine_ia_glossary_parse($glo['contenu'] ?? '') : null;
$st = $g ? antoine_ia_glossary_stats($g) : null;
$saved = antoine_read('glossaire', null);
$res = $saved['result'] ?? null;
$stale = $saved && $kb && glo_kb_hash($kb) !== ($saved['kb_hash'] ?? '');
$langs = antoine_ia_glossary_langs();

function cov_class($t) { return $t >= 90 ? 'c0' : ($t >= 60 ? 'c1' : ($t >= 30 ? 'c2' : 'c3')); }
function cell_html($cell) {
    if ($cell['etat'] === 'absent') return '—';
    $s = h(implode(' ; ', $cell['termes']));
    if ($cell['avert']) $s .= ' <span class="warn" title="Terme marqué ⚠ dans le glossaire">⚠</span>';
    return $s;
}
?><!DOCTYPE html>
<html lang="fr"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title>ElanCité Product AI — cartographie du glossaire</title>
<link rel="icon" href="favicon.ico" sizes="any">
<link rel="icon" href="favicon.svg" type="image/svg+xml">
<link rel="apple-touch-icon" href="apple-touch-icon.png">
<?php echo admin_head_links(); ?>
<style>
.warn{color:var(--red);font-size:12px;font-weight:700}
.ref{color:var(--muted);font-size:13px;margin-top:2px}
.tag{display:inline-block;font-size:12px;font-weight:700;border-radius:4px;padding:1px 7px;background:var(--hover);margin-right:6px}
.tag.forte{background:var(--red-bg)}.tag.moyenne{background:var(--amber-soft)}
.wrap{overflow-x:auto}
table.map{border-collapse:collapse;width:100%;font-size:14px}
table.map th,table.map td{border:1px solid var(--line);padding:6px 8px;text-align:center}
table.map th{background:var(--hover);font-size:13px}
table.map td.name,table.map th.name{text-align:left;white-space:nowrap}
table.map a{color:inherit;text-decoration:none}
td.na{background:var(--bg);color:var(--soft)}
td.c0{background:#ECFDF3}td.c1{background:#FFFAEB}td.c2{background:#FEF0C7}td.c3{background:#FEE4E2}
td .n{font-weight:700;font-size:15px}
td .d{display:block;font-size:11px;color:var(--muted)}
.legend span{display:inline-block;padding:1px 8px;border:1px solid var(--line);margin-right:6px;font-size:12px}
details{border-top:1px solid var(--line);padding:8px 0}
details:first-of-type{border-top:0}
summary{cursor:pointer;font-weight:600}
.item{border-top:1px solid var(--hover);padding:8px 0 8px 12px}
.item:first-of-type{border-top:0}
.why{font-size:14px;margin-top:2px}
.cov{font-size:12px;color:var(--muted);display:block}
td.t{text-align:left;font-size:13px;vertical-align:top}
td.gap{background:#FEE4E2;color:var(--red);font-weight:700}
td.mu{background:var(--amber-soft)}
tr.sechead td{background:var(--hover);font-weight:700;text-align:left}
.ok{color:var(--green)}
<?php echo progress_css(); ?>

</style></head><body>
<?php admin_shell_open('glossaire', 'Cartographie du glossaire de traduction', true); ?>
<?php if ($msg !== ''): ?><div class="msg<?php echo $msgOk ? '' : ' err'; ?>"><?php echo h($msg); ?></div><?php endif; ?>

<section><h2>Glossaire de traduction : couverture</h2>
<?php if (!$glo): ?>
  <p>La page « Glossaire de traduction » n'est pas dans la copie de la base. Relisez la base Notion ci-dessous.</p>
<?php else: ?>
  <p>Calcul sans Claude, d'après la copie de la base lue le <?php echo h(date('d/m/Y à H:i', strtotime($kb['loaded_at'] ?? 'now'))); ?> (UTC). Page « <?php echo h($glo['nom']); ?> », statut <strong><?php echo h($glo['statut']); ?></strong><?php echo $glo['statut'] === 'À valider' ? ' (page non validée : les chiffres reflètent un état provisoire)' : ''; ?>.
  <?php echo count($g['sections']); ?> section(s) lue(s), <?php echo (int) $st['cases']; ?> case(s) de langue (termes × langues des tableaux), dont <strong><?php echo (int) $st['lacunes']; ?></strong> sans terme sourcé (« — ») et <?php echo (int) $st['multi']; ?> avec plusieurs termes.</p>
  <p class="hint">Cette lecture repose sur la mise en forme de la page Notion : titres de niveau 2 par section, tableaux « Terme FR | UK | US | DE | ES | IT | Sources », « — » pour une case sans terme sourcé, plusieurs termes d'une même case séparés par « ; ». Si la page change de forme, signalez-le : le lecteur est dans lib/glossaire.php.</p>
  <?php foreach ($g['erreurs'] as $e): ?><p class="warn">⚠ <?php echo h($e); ?></p><?php endforeach; ?>
<?php endif; ?>
  <form method="post"><input type="hidden" name="csrf" value="<?php echo h($csrf); ?>"><button class="btn" name="reload" value="1">Relire la base Notion maintenant</button> <span class="hint">Sans appel à Claude.</span></form>
</section>

<?php if ($glo && $st): ?>
  <section><h2>Sections × langues</h2>
    <p class="hint legend">Part des termes FR de la section qui ont au moins un terme dans la langue (case « — » = lacune). <span style="background:#ECFDF3">90 % et +</span><span style="background:#FFFAEB">60–89 %</span><span style="background:#FEF0C7">30–59 %</span><span style="background:#FEE4E2">moins de 30 %</span><span style="background:#F4F6F8">non traité = pas de colonne</span> Pour une section en liste (par pays), le nombre d'éléments listés.</p>
    <div class="wrap"><table class="map"><thead><tr><th class="name">Section</th><th>Termes FR</th>
      <?php foreach ($langs as $lg): ?><th><?php echo h($lg); ?></th><?php endforeach; ?></tr></thead><tbody>
    <?php foreach ($st['sections'] as $s): ?>
      <tr><td class="name"><?php echo h($s['titre']); ?></td><td><?php echo $s['type'] === 'liste' ? '<span class="hint">liste</span>' : (int) $s['lignes']; ?></td>
      <?php foreach ($langs as $lg): $v = $s['langues'][$lg];
        if (!empty($v['na'])) { echo '<td class="na" title="Pas de colonne pour cette langue dans la section">non traité</td>'; continue; }
        if (isset($v['nb'])) { echo '<td class="' . ($v['nb'] > 0 ? 'c0' : 'c3') . '"><span class="n">' . (int) $v['nb'] . '</span><span class="cov">élément(s)</span></td>'; continue; } ?>
        <td class="<?php echo cov_class($v['taux']); ?>"><span class="n"><?php echo (int) $v['taux']; ?> %</span><span class="cov"><?php echo (int) ($v['present'] + $v['multi']); ?>/<?php echo (int) ($v['present'] + $v['multi'] + $v['absent']); ?><?php echo $v['multi'] ? ' · ' . (int) $v['multi'] . ' multi' : ''; ?></span></td>
      <?php endforeach; ?></tr>
    <?php endforeach; ?></tbody></table></div>
  </section>

  <section><h2>Termes × langues</h2>
    <p class="hint">Un terme FR par ligne ; les cases « — » (en rouge) sont les lacunes ; les cases à fond orange contiennent plusieurs termes (variantes à arbitrer). Une colonne partagée (par exemple « UK et US ») remplit les deux langues.</p>
    <label><input type="checkbox" id="gaps"> Afficher seulement les lignes avec une lacune</label>
    <div class="wrap"><table class="map" id="grid"><thead><tr><th class="name">Terme FR</th>
      <?php foreach ($langs as $lg): ?><th><?php echo h($lg); ?></th><?php endforeach; ?></tr></thead><tbody>
    <?php foreach ($g['sections'] as $s): if ($s['type'] !== 'table') continue; ?>
      <tr class="sechead" data-sec="1"><td colspan="<?php echo 1 + count($langs); ?>"><?php echo h($s['titre']); ?><?php echo $s['partage'] ? ' <span class="hint">(colonne partagée : ' . h(implode(' / ', array_map(function ($p) { return implode(' + ', $p); }, $s['partage']))) . ')</span>' : ''; ?></td></tr>
      <?php foreach ($s['lignes'] as $l):
        $gap = false; foreach ($langs as $lg) if (!empty($s['cols'][$lg]) && ($l['cases'][$lg]['etat'] ?? 'absent') === 'absent') $gap = true; ?>
        <tr data-gap="<?php echo $gap ? '1' : '0'; ?>"><td class="t name" style="white-space:normal"><?php echo $l['fr_absent'] ? '<span class="hint">(pas de terme FR)</span>' : h($l['fr']); ?></td>
        <?php foreach ($langs as $lg):
          if (empty($s['cols'][$lg])) { echo '<td class="na">n/t</td>'; continue; }
          $cell = $l['cases'][$lg] ?? ['etat' => 'absent', 'termes' => [], 'avert' => false]; ?>
          <td class="t <?php echo $cell['etat'] === 'absent' ? 'gap' : ($cell['etat'] === 'multi' ? 'mu' : ''); ?>"><?php echo cell_html($cell); ?></td>
        <?php endforeach; ?></tr>
      <?php endforeach; ?>
    <?php endforeach; ?></tbody></table></div>
    <p class="hint">n/t = langue sans colonne dans la section (non traitée). Les sections en liste par pays ne figurent pas dans cette grille (voir « Sections × langues »).</p>
  </section>

  <section><h2>Langues × sources</h2>
    <p class="hint">Nombre de termes présents, par langue et par code de source. <strong>Approximation</strong> : la colonne « Sources » est renseignée par ligne et non par langue ; un terme est rattaché à tous les codes cités sur sa ligne (un terme peut donc compter sous plusieurs codes), et « (autres) » regroupe les lignes sans code connu de la liste « Codes de sources » de la page.</p>
    <div class="wrap"><table class="map"><thead><tr><th class="name">Langue</th>
      <?php foreach ($st['codes_utilises'] as $code): ?><th title="<?php echo h($g['codes'][$code] ?? ''); ?>"><?php echo h($code); ?></th><?php endforeach; ?></tr></thead><tbody>
    <?php foreach ($langs as $lg): ?>
      <tr><td class="name"><?php echo h($lg); ?></td>
      <?php foreach ($st['codes_utilises'] as $code): $n = $st['sources'][$lg][$code] ?? 0; ?>
        <td class="<?php echo $n === 0 ? 'na' : ''; ?>"><?php echo $n ?: '—'; ?></td>
      <?php endforeach; ?></tr>
    <?php endforeach; ?></tbody></table></div>
    <?php if ($g['codes']): ?><p class="hint">Codes : <?php echo h(implode(' · ', array_map(function ($k, $v) { return "$k = $v"; }, array_keys($g['codes']), $g['codes']))); ?></p><?php endif; ?>
  </section>
<?php endif; ?>

<section><h2>Termes utilisés mais absents du glossaire</h2>
  <p>Claude lit les pages produit et marque (pages « À valider » comprises) et liste les termes qui n'ont pas de ligne dans le glossaire. Ce sont des <strong>pistes déduites</strong> : le serveur vérifie que le terme figure bien dans l'extrait cité, que la page citée existe et que le terme n'est pas déjà au glossaire. Aucune traduction n'est proposée et rien n'est écrit dans Notion.</p>
  <form id="run-form" data-csrf="<?php echo h($csrf); ?>" data-running="<?php echo $running ? '1' : '0'; ?>">
    <label><input type="checkbox" name="refresh" value="1" checked> Relire la base Notion avant la recherche</label><br><br>
    <button class="btn" id="run-btn" type="submit"><?php echo $saved ? 'Relancer la recherche' : 'Lancer la recherche'; ?></button>
    <span class="hint">Un appel à Claude, compté dans la consommation (« Admin (glossaire) »).</span>
  </form>
  <?php echo progress_panel_html('Recherche en cours…'); ?>
</section>

<?php if (!$res): ?>
  <section><p>Aucune recherche de termes absents n'a encore été lancée.</p></section>
<?php else: ?>
  <section><h2>Dernière recherche</h2>
    <p class="hint">Générée le <?php echo h(date('d/m/Y à H:i', strtotime($saved['generated_at']))); ?> (UTC), sur <?php echo (int) $saved['pages']; ?> pages produit, modèle <?php echo h($saved['model']); ?>, coût estimé <?php echo number_format((float) $saved['cost'], 2, ',', ' '); ?> $. <?php echo (int) $saved['termes']; ?> terme(s) retenu(s), dont <?php echo (int) $saved['avertissements']; ?> à vérifier avec soin (⚠) ; <?php echo (int) ($res['ecartes'] ?? 0); ?> écarté(s) par le serveur (déjà au glossaire, doublon ou cité seulement dans le glossaire).</p>
    <?php if ($stale): ?><p class="warn">La base a été modifiée depuis cette recherche : relancez-la pour tenir compte des changements.</p><?php endif; ?>
    <?php if ($res['synthese'] !== ''): ?><p><?php echo nl2br(h($res['synthese'])); ?></p><?php endif; ?>
    <p class="hint">⚠ signale un point que le serveur n'a pas pu confirmer (langue inconnue, référence ou extrait introuvable, terme absent de l'extrait).</p>
    <?php if (!$res['termes']): ?><p class="ok">Aucun terme absent du glossaire n'a été retenu.</p><?php else:
      $by = [];
      foreach ($res['termes'] as $t) $by[$t['section_glossaire']][] = $t; ?>
      <?php foreach ($by as $sec => $l): ?>
        <details open><summary><?php echo h($sec); ?> — <?php echo count($l); ?> terme(s)<?php echo $l[0]['section_existante'] ? '' : ' <span class="hint">(section à créer)</span>'; ?></summary>
        <?php foreach ($l as $t): ?>
          <div class="item"><span class="tag"><?php echo h($t['langue']); ?></span><strong><?php echo h($t['terme']); ?></strong>
            <?php foreach ($t['_avert'] as $w): ?> <span class="warn">⚠ <?php echo h($w); ?></span><?php endforeach; ?>
            <?php if ($t['pourquoi'] !== ''): ?><div class="why"><?php echo h($t['pourquoi']); ?></div><?php endif; ?>
            <?php if ($t['extrait'] !== ''): ?><div class="ref">« <?php echo h($t['extrait']); ?> »</div><?php endif; ?>
            <?php if ($t['reference'] !== ''): ?><div class="ref"><?php echo h($t['reference']); ?></div><?php endif; ?></div>
        <?php endforeach; ?></details>
      <?php endforeach; ?>
    <?php endif; ?>
  </section>
<?php endif; ?>
<?php admin_shell_close(); ?>
<script>
(function () {
  var cb = document.getElementById('gaps'); if (!cb) return;
  cb.addEventListener('change', function () {
    var rows = document.querySelectorAll('#grid tbody tr');
    for (var i = 0; i < rows.length; i++) {
      var r = rows[i];
      if (r.getAttribute('data-sec')) { r.style.display = ''; continue; }
      r.style.display = (cb.checked && r.getAttribute('data-gap') !== '1') ? 'none' : '';
    }
  });
})();
<?php echo progress_js(['endpoint' => 'glossaire.php', 'running' => 'Recherche en cours…', 'done' => 'Recherche terminée', 'fail' => 'Recherche en échec',
  'btn_running' => 'Recherche en cours…', 'btn_again' => 'Relancer la recherche',
  'pct_hint' => "(estimation : l'étape « Recherche des termes absents par Claude » représente l'essentiel du temps)", 'busy' => 'Un traitement est déjà en cours : suivi de son avancement']); ?>
</script>
</body></html>
