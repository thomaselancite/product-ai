<?php
/* ElanCité Product AI — administration : consommation et relecture de la base Notion. */
define('ANTOINE', 1);
require __DIR__ . '/lib/core.php';
require __DIR__ . '/lib/notion.php';

session_start();
header('X-Robots-Tag: noindex, nofollow');
header('Cache-Control: no-store');
$c = antoine_config();
$pwd = (string) ($c['admin_password'] ?? '');
$configured = strlen($pwd) >= 12 && strpos($pwd, 'REMPLACER') === false;
$msg = ''; $ok = true;

if (isset($_POST['logout'])) { $_SESSION = []; session_destroy(); header('Location: admin.php'); exit; }
if (isset($_POST['password']) && $configured) {
    usleep(500000);
    if (hash_equals($pwd, (string) $_POST['password'])) { session_regenerate_id(true); $_SESSION['antoine_admin'] = true; $_SESSION['csrf'] = bin2hex(random_bytes(16)); }
    else { $msg = 'Mot de passe incorrect.'; $ok = false; }
}
$logged = !empty($_SESSION['antoine_admin']);

if ($logged && isset($_POST['refresh']) && hash_equals($_SESSION['csrf'] ?? '', (string) ($_POST['csrf'] ?? ''))) {
    try {
        $kb = antoine_ia_load_kb(true);
        $autres = $kb['brand'] ? ['Marque & positionnement'] : [];
        foreach ($kb['transverses'] as $t) $autres[] = $t['nom'] . (empty($t['erreur']) ? ' (' . $t['statut'] . ')' : ' (illisible : ' . $t['erreur'] . ')');
        $msg = count($kb['pages']) . ' pages produits lues dans Notion'
             . (count($autres) > 1 ? ', plus les pages ' . implode(', ', array_slice($autres, 0, -1)) . ' et ' . end($autres) : ($autres ? ', plus la page ' . $autres[0] : '')) . '.';
        if ($kb['transverses_erreur']) { $msg .= " Sous-pages de l'accueil non lues : " . $kb['transverses_erreur']; $ok = false; }
    } catch (AntoineError $e) { $msg = $e->getMessage(); $ok = false; }
}
function h($s) { return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8'); }
function key_ok($v) { return $v && strpos($v, 'REMPLACER') === false; }
?><!DOCTYPE html>
<html lang="fr"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title>ElanCité Product AI — administration</title>
<link rel="icon" href="favicon.ico" sizes="any">
<link rel="icon" href="favicon.svg" type="image/svg+xml">
<link rel="apple-touch-icon" href="apple-touch-icon.png">
<style>
body{font-family:"Segoe UI",Roboto,Helvetica,Arial,sans-serif;background:#EEF1F3;color:#22272B;margin:0}
header{background:#2E3439;color:#fff;padding:16px 24px;border-bottom:6px solid #C8102E}
header h1{margin:0;font-size:20px}
main{max-width:860px;margin:0 auto;padding:24px}
section{background:#fff;border:1px solid #D3D9DE;border-radius:6px;padding:18px 20px;margin-bottom:18px}
h2{font-size:17px;margin:0 0 12px}
table{border-collapse:collapse;width:100%;font-size:14px}
td,th{border-bottom:1px solid #D3D9DE;padding:6px 8px;text-align:left}
td.n,th.n{text-align:right}
.btn{border:0;border-radius:6px;padding:9px 16px;font-weight:700;background:#2E3439;color:#fff;cursor:pointer;font:inherit}
.msg{padding:10px 14px;border-left:4px solid #2E7D4F;background:#fff;margin-bottom:18px}
.msg.err{border-left-color:#B3261E}
.hint{color:#5E6A72;font-size:13px}
input[type=password]{padding:8px 10px;border:1px solid #D3D9DE;border-radius:6px;width:260px}
</style></head><body>
<header><h1>ElanCité Product AI — administration</h1></header>
<main>
<?php if ($msg): ?><div class="msg<?php echo $ok ? '' : ' err'; ?>"><?php echo h($msg); ?></div><?php endif; ?>

<?php if (!$configured): ?>
  <section><h2>Configuration incomplète</h2><p>Renseignez un mot de passe d'administration d'au moins 12 caractères dans <code>config.php</code> (clé <code>admin_password</code>).</p></section>
<?php elseif (!$logged): ?>
  <section><h2>Connexion</h2>
    <form method="post"><input type="password" name="password" autocomplete="current-password" aria-label="Mot de passe"> <button class="btn">Se connecter</button></form>
  </section>
<?php else: $usage = antoine_read('usage', []); $cache = antoine_read('kb', null); ?>
  <section><h2>État</h2>
    <table>
      <tr><td>Clé d'API Claude</td><td><?php echo key_ok($c['anthropic_key']) ? 'renseignée' : '<strong>à renseigner dans config.php</strong>'; ?></td></tr>
      <tr><td>Jeton Notion</td><td><?php echo key_ok($c['notion_token']) ? 'renseigné' : '<strong>à renseigner dans config.php</strong>'; ?></td></tr>
      <tr><td>Agences</td><td><?php echo count($c['agencies']); ?> (à gérer dans config.php)</td></tr>
      <tr><td>Limites</td><td><?php echo (int) $c['hourly_limit']; ?> appels par agence et par heure ; budget de <?php echo h($c['monthly_budget']); ?> $ par mois</td></tr>
      <tr><td>Consignes de Claude</td><td><?php
        $cs = $cache['kb']['consignes'] ?? null;
        if (!$cache) echo 'base pas encore lue';
        elseif (!$cs) echo "page « Consignes Claude » introuvable sous la page d'accueil : consignes par défaut du code utilisées";
        elseif (isset($cs['erreur'])) echo 'lecture impossible (' . h($cs['erreur']) . ') : consignes par défaut du code utilisées';
        else { $ok3 = array_intersect(['relecture', 'brief', 'questions'], array_keys(array_filter($cs))); $miss = array_diff(['relecture', 'brief', 'questions'], $ok3);
               echo 'lues dans Notion' . ($miss ? ' ; sections absentes, remplacées par les consignes par défaut : ' . h(implode(', ', $miss)) : ' (Relecture, Brief, Questions)'); } ?></td></tr>
      <tr><td>Autres pages de la base</td><td><?php
        if (!$cache) echo 'base pas encore lue';
        elseif (!empty($cache['kb']['transverses_erreur'])) echo "sous-pages de l'accueil non lues : " . h($cache['kb']['transverses_erreur']);
        elseif (empty($cache['kb']['transverses'])) echo "aucune sous-page de l'accueil en plus de Marque & positionnement et Consignes Claude";
        else echo h(implode(' ; ', array_map(function ($t) { return $t['nom'] . (empty($t['erreur']) ? ' (' . $t['statut'] . ')' : ' : illisible (' . $t['erreur'] . ')'); }, $cache['kb']['transverses']))); ?></td></tr>
      <tr><td>Base en cache</td><td><?php echo $cache ? 'lue le ' . h(date('d/m/Y à H:i', strtotime($cache['kb']['loaded_at']))) . ' (UTC)' : 'pas encore lue'; ?></td></tr>
    </table>
    <form method="post" style="margin-top:14px"><input type="hidden" name="csrf" value="<?php echo h($_SESSION['csrf']); ?>">
      <button class="btn" name="refresh" value="1">Relire la base Notion maintenant</button>
      <span class="hint">Vide le cache et vérifie la connexion à Notion.</span></form>
  </section>
  <section><h2>Consommation estimée</h2>
    <?php if (!$usage): ?><p>Aucun appel à Claude pour le moment.</p><?php else: ?>
    <table><thead><tr><th>Mois</th><th>Agence</th><th class="n">Appels</th><th class="n">Coût estimé ($)</th></tr></thead><tbody>
    <?php foreach ($usage as $m => $u): foreach ($u['agencies'] as $ag => $v): ?>
      <tr><td><?php echo h($m); ?></td><td><?php echo h($ag); ?></td><td class="n"><?php echo (int) $v['calls']; ?></td><td class="n"><?php echo number_format($v['cost'], 2, ',', ' '); ?></td></tr>
    <?php endforeach; ?>
      <tr><td><strong><?php echo h($m); ?></strong></td><td><strong>Total</strong></td><td></td><td class="n"><strong><?php echo number_format($u['total'], 2, ',', ' '); ?></strong></td></tr>
    <?php endforeach; ?></tbody></table>
    <p class="hint">Estimation calculée avec les tarifs de config.php. Le montant facturé est celui de la console Claude (platform.claude.com).</p>
    <?php endif; ?>
  </section>
  <section><h2>Analyse de la base</h2>
    <p>Questions pour affiner la connaissance produit, incohérences, idées de compléments et points ouverts, d'après l'état de la base.</p>
    <a class="btn" href="analyse.php" style="text-decoration:none;display:inline-block">Ouvrir l'analyse de la base</a>
  </section>
  <form method="post"><button class="btn" name="logout" value="1" style="background:#5E6A72">Se déconnecter</button></form>
<?php endif; ?>
</main></body></html>
