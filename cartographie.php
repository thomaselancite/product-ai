<?php
/*
 * ElanCité Product AI — cartographie des sujets manquants, par produit et par marché
 * (réservée à l'administrateur connecté via admin.php, lecture seule : rien n'est écrit dans Notion).
 *
 * Même mécanique que analyse.php : le traitement s'exécute dans une requête POST (?action=run) qui écrit son
 * avancement dans data/cartographie.progress.php (voir lib/progress.php) ; la page le lit toutes les secondes.
 * La carte des marchés (commercialisé / documenté) vient des propriétés Notion ; seuls les sujets manquants
 * sont déduits par Claude, puis contrôlés par le serveur (marchés, catégories, références).
 */
define('ANTOINE', 1);
require __DIR__ . '/lib/core.php';
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

if (!$logged) {
    if ($action !== '') progress_json(['error' => 'Session expirée : reconnectez-vous via admin.php.'], 401);
    header('Location: admin.php');
    exit;
}

/** Empreinte du texte de la base tel que transmis à Claude (pages « À valider » comprises). */
function carto_kb_hash($kb) { return sha1(antoine_ia_kb_text($kb, true)['text']); }

function carto_steps($refresh) {
    return [['controles', 'Contrôles préalables'],
            ['lecture', $refresh ? 'Lecture de la base dans Notion' : 'Lecture de la base (copie en cache)'],
            ['preparation', 'Préparation du contexte'],
            ['claude', 'Cartographie par Claude'],
            ['reponse', 'Lecture de la réponse'],
            ['verification', 'Vérification des marchés, catégories et références'],
            ['enregistrement', 'Enregistrement de la cartographie']];
}

/** Exécute la cartographie en publiant chaque étape. Renvoie [succès, message]. */
function carto_run($c, $refresh, RunProgress $P) {
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
        $nProduits = $kbText['used_count'];
        $P->done('lecture', $nProduits . ' page(s) produit retenue(s). Base lue le ' . date('d/m/Y à H:i', strtotime($kb['loaded_at'] ?? 'now')) . ' (UTC).', 15);

        $step = 'preparation'; $P->start('preparation');
        $built = antoine_ia_build_map($kb['consignes'] ?? null);
        $source = (!empty($kb['consignes']) && !isset($kb['consignes']['erreur']) && !empty($kb['consignes']['cartographie'])) ? 'section « Cartographie » de la page Notion' : 'consignes par défaut du dépôt';
        $P->done('preparation', number_format(mb_strlen($kbText['text']), 0, ',', ' ') . " caractères de base transmis à Claude ; consignes : $source.", 18);

        $step = 'claude'; $P->start('claude', 'Envoi à Claude, en attente de la première réponse…');
        $buf = ''; $t0 = microtime(true);
        $onText = function ($t) use (&$buf, $P, $nProduits) {
            $buf .= $t;
            $n = substr_count($buf, '"produit"');
            $inCats = $n === 0;
            $frac = $inCats ? min(0.1, strlen($buf) / 3000) : min(1, 0.1 + 0.9 * ($n - 0.5) / max(1, $nProduits));
            $detail = number_format(mb_strlen($buf), 0, ',', ' ') . ' caractères reçus · ' . ($inCats ? 'liste des catégories' : min($n, $nProduits) . ' produit(s) sur ' . $nProduits . ' en cours de cartographie');
            $P->tick('claude', $detail, 18 + 70 * $frac);
            return true; // le traitement va au bout même si la page est fermée
        };
        $stop = '';
        [$text, $cost, $err] = antoine_ia_claude_stream($kbText['text'], $built['instructions'], $built['messages'], $c, $onText, $stop);
        // La consommation est comptée même en cas d'erreur
        if ($cost > 0) antoine_update('usage', [], function ($u) use ($month, $cost) {
            if (!isset($u[$month])) $u[$month] = ['total' => 0, 'agencies' => []];
            $u[$month]['total'] = round($u[$month]['total'] + $cost, 6);
            $a = $u[$month]['agencies']['Admin (cartographie)'] ?? ['calls' => 0, 'cost' => 0];
            $u[$month]['agencies']['Admin (cartographie)'] = ['calls' => $a['calls'] + 1, 'cost' => round($a['cost'] + $cost, 6)];
            krsort($u);
            return array_slice($u, 0, 12, true);
        });
        if ($err) throw $err;
        if ($stop === 'max_tokens') {
            antoine_write('cartographie.brut', ['at' => gmdate('c'), 'stop_reason' => $stop, 'text' => $text]);
            throw new AntoineError('antoine_too_long', 'La réponse de Claude a été coupée : la limite de longueur (max_tokens, actuellement ' . (int) $c['max_tokens'] . ' dans config.php) est atteinte, réflexion comprise. Augmentez-la puis relancez (consommation comptée). Texte reçu conservé dans data/cartographie.brut.php.', 502);
        }
        $secs = max(1, round(microtime(true) - $t0));
        $P->done('claude', number_format(mb_strlen($text), 0, ',', ' ') . " caractères reçus en $secs s ; coût estimé " . number_format($cost, 2, ',', ' ') . ' $.', 88);

        $step = 'reponse'; $P->start('reponse');
        $data = antoine_ia_parse_json($text);
        if ($data === null) {
            antoine_write('cartographie.brut', ['at' => gmdate('c'), 'stop_reason' => $stop, 'text' => $text]);
            $n = mb_strlen($text);
            throw new AntoineError('antoine_invalid_json', "La réponse de Claude n'a pas pu être lue (consommation comptée) : JSON invalide. Fin de réponse annoncée : " . ($stop ?: 'inconnue') . ". Début : « " . trim(mb_substr($text, 0, 150)) . ' » — fin : « ' . trim(mb_substr($text, max(0, $n - 150))) . ' ». Texte complet conservé dans data/cartographie.brut.php.', 502);
        }
        $P->done('reponse', 'Réponse lue : ' . count(is_array($data['produits'] ?? null) ? $data['produits'] : []) . ' produit(s), ' . count(is_array($data['categories'] ?? null) ? $data['categories'] : []) . ' catégorie(s).', 90);

        $step = 'verification'; $P->start('verification', 'Contrôle des marchés, des catégories et des références…');
        [$map, $total, $warn] = antoine_ia_map_check($data, $kb);
        $P->done('verification', "$total sujet(s) manquant(s) contrôlé(s), $warn à vérifier avec soin (⚠).", 95);

        $step = 'enregistrement'; $P->start('enregistrement');
        antoine_write('cartographie', [
            'generated_at' => gmdate('c'), 'kb_loaded_at' => $kb['loaded_at'] ?? '', 'kb_hash' => carto_kb_hash($kb),
            'model' => $c['model'], 'cost' => round($cost, 4), 'pages' => $kbText['used_count'],
            'manques' => $total, 'avertissements' => $warn, 'map' => $map,
        ]);
        $P->done('enregistrement', 'Cartographie conservée sur le serveur ; consommation comptée sous « Admin (cartographie) ».', 100);
        $msg = "Cartographie terminée : $total sujet(s) manquant(s), dont $warn à vérifier avec soin. Coût estimé : " . number_format($cost, 2, ',', ' ') . ' $.';
        $P->finish($msg);
        return [true, $msg];
    } catch (AntoineError $e) {
        $P->fail($step, $e->getMessage());
        return [false, $e->getMessage()];
    } catch (Throwable $e) {
        error_log('ElanCité Product AI (cartographie) : ' . $e->getMessage());
        $why = get_class($e) . ' : ' . $e->getMessage() . ' (' . basename($e->getFile()) . ':' . $e->getLine() . ')'; // page réservée à l'admin : la cause exacte est utile
        $P->fail($step, 'Erreur interne du serveur — ' . $why);
        return [false, 'Erreur interne du serveur — ' . $why];
    }
}

$c = antoine_config();

if ($action === 'run' && ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    [$P, $lock] = progress_start_run('cartographie', carto_steps(!empty($_POST['refresh'])), $csrf, 'cartographie');
    [$ok, $msg] = carto_run($c, !empty($_POST['refresh']), $P);
    progress_end_run($lock);
    progress_json(['ok' => $ok, 'message' => $msg]);
}
if ($action === 'progress') progress_poll('cartographie');

session_write_close();

/* ---------- Page ---------- */
$running = progress_running('cartographie');
$saved = antoine_read('cartographie', null);
$stale = false;
if ($saved) {
    $cache = antoine_read('kb', null);
    if ($cache && isset($cache['kb'])) $stale = carto_kb_hash($cache['kb']) !== ($saved['kb_hash'] ?? '');
}

$markets = antoine_ia_markets();
$short = ['Distributeurs (reste du monde)' => 'Distrib.'];
function mk_label($m) { global $short; return $short[$m] ?? $m; }
function cell_class($n) { return $n === 0 ? 'c0' : ($n <= 2 ? 'c1' : ($n <= 4 ? 'c2' : 'c3')); }

$map = $saved['map'] ?? null;
$cats = $map['categories'] ?? [];
// Comptages : produit × marché, produit × catégorie, et sujets par marché
$perMarket = array_fill_keys($markets, []);
$imp = ['forte' => 0, 'moyenne' => 1, 'faible' => 2];
if ($map) foreach ($map['produits'] as $pi => $row) foreach ($row['manques'] as $m) foreach ($m['marches'] as $mk) {
    if (isset($perMarket[$mk])) $perMarket[$mk][] = ['p' => $row['produit'], 'pi' => $pi] + $m;
}
foreach ($perMarket as $mk => $l) { usort($perMarket[$mk], function ($a, $b) use ($imp) { return $imp[$a['importance']] <=> $imp[$b['importance']]; }); }
?><!DOCTYPE html>
<html lang="fr"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title>ElanCité Product AI — cartographie des sujets manquants</title>
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
<?php echo progress_css(); ?>

</style></head><body>
<header><h1>ElanCité Product AI — cartographie des sujets manquants</h1> <a href="admin.php">← Administration</a></header>
<main>

<section><h2>Cartographie des sujets manquants</h2>
  <p>Pour chaque produit de la base (pages « À valider » comprises, pages « Obsolète » exclues), Claude déduit de la lecture les sujets qui manquent et les marchés concernés. Les marchés de chaque produit et leur niveau de documentation viennent des propriétés Notion « Marchés » et « Marchés documentés » ; les sujets manquants sont des <strong>pistes déduites</strong>, à vérifier : rien n'est écrit dans Notion, et les compléments se traitent ensuite dans le projet Claude.</p>
  <form id="run-form" data-csrf="<?php echo h($csrf); ?>" data-running="<?php echo $running ? '1' : '0'; ?>">
    <label><input type="checkbox" name="refresh" value="1" checked> Relire la base Notion avant la cartographie</label><br><br>
    <button class="btn" id="run-btn" type="submit"><?php echo $saved ? 'Relancer la cartographie' : 'Lancer la cartographie'; ?></button>
    <span class="hint">Un appel à Claude, compté dans la consommation (« Admin (cartographie) »).</span>
  </form>
  <?php echo progress_panel_html('Cartographie en cours…'); ?>
</section>

<?php if (!$map): ?>
  <section><p>Aucune cartographie n'a encore été lancée.</p></section>
<?php else: ?>
  <section><h2>Dernière cartographie</h2>
    <p class="hint">Générée le <?php echo h(date('d/m/Y à H:i', strtotime($saved['generated_at']))); ?> (UTC), sur <?php echo (int) $saved['pages']; ?> pages produit, modèle <?php echo h($saved['model']); ?>, coût estimé <?php echo number_format((float) $saved['cost'], 2, ',', ' '); ?> $. <?php echo (int) $saved['manques']; ?> sujet(s) manquant(s), dont <?php echo (int) $saved['avertissements']; ?> à vérifier avec soin (⚠).</p>
    <?php if ($stale): ?><p class="warn">La base a été modifiée depuis cette cartographie : relancez-la pour tenir compte des changements.</p><?php endif; ?>
    <?php if ($map['synthese'] !== ''): ?><p><?php echo nl2br(h($map['synthese'])); ?></p><?php endif; ?>
    <?php if (!empty($map['ignores'])): ?><p class="hint"><?php echo (int) $map['ignores']; ?> produit(s) cité(s) par Claude n'existent pas dans la base et ont été écartés.</p><?php endif; ?>
    <p class="hint">⚠ signale un point que le serveur n'a pas pu confirmer (référence introuvable dans la base, marché que le produit ne couvre pas, catégorie hors liste, produit absent de la réponse).</p>
  </section>

  <section><h2>Produits × marchés</h2>
    <p class="hint legend">Nombre de sujets manquants par marché. <span class="c0" style="background:#E4F2E9">0</span><span style="background:#FFF6D6">1–2</span><span style="background:#FFE0B8">3–4</span><span style="background:#F8C9C9">5 et +</span><span style="background:#F4F6F8">— marché non commercialisé</span> « doc. » = source dédiée au produit pour ce marché ; « sans source » = aucune (propriété « Marchés documentés »).</p>
    <div class="wrap"><table class="map"><thead><tr><th class="name">Produit</th><th>Famille</th>
      <?php foreach ($markets as $mk): ?><th><?php echo h(mk_label($mk)); ?></th><?php endforeach; ?><th>Total</th></tr></thead><tbody>
    <?php foreach ($map['produits'] as $pi => $row): ?>
      <tr><td class="name"><a href="#prod-<?php echo $pi; ?>"><?php echo h($row['produit']); ?></a><?php echo $row['statut'] === 'À valider' ? ' <span class="hint">(à valider)</span>' : ''; ?><?php echo !$row['analyse'] ? ' <span class="warn" title="Claude n\'a pas cartographié ce produit">⚠ non analysé</span>' : ''; ?></td>
        <td class="hint"><?php echo h($row['famille']); ?></td>
      <?php foreach ($markets as $mk):
        if (!in_array($mk, $row['marches'], true)) { echo '<td class="na">—</td>'; continue; }
        $n = 0; foreach ($row['manques'] as $m) if (in_array($mk, $m['marches'], true)) $n++;
        $doc = in_array($mk, $row['marches_doc'], true); ?>
        <td class="<?php echo $row['analyse'] ? cell_class($n) : 'na'; ?>"><a href="#prod-<?php echo $pi; ?>"><span class="n"><?php echo $row['analyse'] ? $n : '?'; ?></span></a><span class="d"><?php echo $doc ? 'doc.' : 'sans source'; ?></span></td>
      <?php endforeach; ?>
        <td><strong><?php echo count($row['manques']); ?></strong></td></tr>
    <?php endforeach; ?></tbody></table></div>
  </section>

  <section><h2>Produits × catégories</h2>
    <p class="hint">Nombre de sujets manquants par catégorie. Les catégories sont définies par Claude pour l'ensemble de la base ; une catégorie hors liste est signalée par ⚠.</p>
    <div class="wrap"><table class="map"><thead><tr><th class="name">Produit</th>
      <?php foreach ($cats as $ct): ?><th><?php echo h($ct); ?></th><?php endforeach; ?></tr></thead><tbody>
    <?php foreach ($map['produits'] as $pi => $row): ?>
      <tr><td class="name"><a href="#prod-<?php echo $pi; ?>"><?php echo h($row['produit']); ?></a></td>
      <?php foreach ($cats as $ct):
        $n = 0; foreach ($row['manques'] as $m) if ($m['categorie'] === $ct) $n++; ?>
        <td class="<?php echo $row['analyse'] ? cell_class($n) : 'na'; ?>"><span class="n"><?php echo $row['analyse'] ? $n : '?'; ?></span></td>
      <?php endforeach; ?></tr>
    <?php endforeach; ?></tbody></table></div>
  </section>

  <section><h2>Par marché</h2>
    <?php foreach ($markets as $mk): $l = $perMarket[$mk]; ?>
      <details><summary><?php echo h($mk); ?> — <?php echo count($l); ?> sujet(s) manquant(s)</summary>
      <?php if (!$l): ?><p class="hint">Aucun sujet manquant signalé pour ce marché.</p><?php endif; ?>
      <?php foreach ($l as $m): ?>
        <div class="item"><span class="tag <?php echo h($m['importance']); ?>"><?php echo h($m['importance']); ?></span><strong><?php echo h($m['sujet']); ?></strong> <span class="hint">— <?php echo h($m['p']); ?> · <?php echo h($m['categorie']); ?></span>
          <?php if ($m['pourquoi'] !== ''): ?><div class="why"><?php echo h($m['pourquoi']); ?></div><?php endif; ?>
          <?php if ($m['reference'] !== ''): ?><div class="ref"><?php echo h($m['reference']); ?><?php echo $m['_ref_ok'] === false ? ' <span class="warn">⚠ référence introuvable</span>' : ''; ?></div><?php endif; ?></div>
      <?php endforeach; ?></details>
    <?php endforeach; ?>
  </section>

  <section><h2>Détail par produit</h2>
    <?php foreach ($map['produits'] as $pi => $row): ?>
      <details id="prod-<?php echo $pi; ?>"><summary><?php echo h($row['produit']); ?> — <?php echo count($row['manques']); ?> sujet(s) manquant(s)</summary>
      <p class="hint">Marchés : <?php echo h(implode(', ', $row['marches']) ?: 'non renseignés'); ?> · documentés : <?php echo h(implode(', ', $row['marches_doc']) ?: 'aucun'); ?></p>
      <?php if (!$row['analyse']): ?><p class="warn">⚠ Claude n'a pas cartographié ce produit : relancez la cartographie.</p><?php endif; ?>
      <?php if ($row['analyse'] && !$row['manques']): ?><p class="hint">Aucun sujet manquant signalé.</p><?php endif; ?>
      <?php foreach ($row['manques'] as $m): ?>
        <div class="item"><span class="tag <?php echo h($m['importance']); ?>"><?php echo h($m['importance']); ?></span><strong><?php echo h($m['sujet']); ?></strong>
          <span class="hint">— <?php echo h($m['categorie']); ?> · <?php echo h(count($m['marches']) === count($row['marches']) ? 'tous les marchés du produit' : implode(', ', $m['marches'])); ?></span>
          <?php foreach ($m['_avert'] as $w): ?> <span class="warn">⚠ <?php echo h($w); ?></span><?php endforeach; ?>
          <?php if ($m['pourquoi'] !== ''): ?><div class="why"><?php echo h($m['pourquoi']); ?></div><?php endif; ?>
          <?php if ($m['reference'] !== ''): ?><div class="ref"><?php echo h($m['reference']); ?></div><?php endif; ?></div>
      <?php endforeach; ?>
      <?php if ($row['tronques'] > 0): ?><p class="hint"><?php echo (int) $row['tronques']; ?> sujet(s) de moindre importance non affichés.</p><?php endif; ?>
      </details>
    <?php endforeach; ?>
  </section>
<?php endif; ?>
</main>
<script>
<?php echo progress_js(['endpoint' => 'cartographie.php', 'running' => 'Cartographie en cours…', 'done' => 'Cartographie terminée', 'fail' => 'Cartographie en échec',
  'btn_running' => 'Cartographie en cours…', 'btn_again' => 'Relancer la cartographie',
  'pct_hint' => "(estimation : l'étape « Cartographie par Claude » représente l'essentiel du temps)", 'busy' => 'Un traitement est déjà en cours : suivi de son avancement']); ?>
</script>
</body></html>
