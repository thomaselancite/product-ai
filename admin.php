<?php
/* ElanCité Product AI — administration : consommation et relecture de la base Notion. */
define('ANTOINE', 1);
require __DIR__ . '/lib/core.php';
require __DIR__ . '/lib/admin_ui.php';
require __DIR__ . '/lib/notion.php';

header('X-Robots-Tag: noindex, nofollow');
header('Cache-Control: no-store');
$c = antoine_config();
$msg = ''; $ok = true;

if (isset($_POST['logout'])) { antoine_logout(); header('Location: admin.php'); exit; }
if (isset($_POST['login'])) {
    try { antoine_login($_POST['login'], $_POST['password'] ?? ''); header('Location: admin.php'); exit; }
    catch (AntoineError $e) { $msg = $e->getMessage(); $ok = false; }
}
$user = antoine_session_user();
$logged = $user !== null && $user['role'] === 'admin';
$configured = antoine_has_active_admin() || antoine_admin_password_configured();

if ($logged && isset($_POST['refresh']) && antoine_csrf_ok($_POST['csrf'] ?? '')) {
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
<?php echo admin_head_links(); ?>
<style>
table{border-collapse:collapse;width:100%;font-size:14px}
td,th{border-bottom:1px solid var(--line);padding:6px 8px;text-align:left}
td.n,th.n{text-align:right}
</style></head><body>
<?php admin_shell_open('admin', 'Administration', !empty($logged)); ?>
<?php if ($msg): ?><div class="msg<?php echo $ok ? '' : ' err'; ?>"><?php echo h($msg); ?></div><?php endif; ?>

<?php if (!$configured): ?>
  <section><h2>Configuration incomplète</h2><p>Aucun compte administrateur : renseignez un mot de passe d'administration d'au moins 12 caractères dans <code>config.php</code> (clé <code>admin_password</code>).</p></section>
<?php elseif ($user !== null && !$logged): ?>
  <section><h2>Accès réservé</h2><p>Votre compte (<?php echo h($user['name']); ?>) n'a pas accès à l'administration.</p>
    <form method="post"><button class="btn secondary" name="logout" value="1">Se déconnecter</button></form>
  </section>
<?php elseif (!$logged): ?>
  <section><h2>Connexion</h2>
    <form method="post" style="display:grid;gap:12px;max-width:360px">
      <label style="display:grid;gap:4px">Identifiant <input type="text" name="login" autocomplete="username" autocapitalize="none" spellcheck="false" maxlength="32" required></label>
      <label style="display:grid;gap:4px">Mot de passe <input type="password" name="password" autocomplete="current-password" required></label>
      <button class="btn">Se connecter</button>
    </form>
  </section>
<?php else: $usage = antoine_read('usage', []); $cache = antoine_read('kb', null); ?>
  <section><h2>État</h2>
    <table>
      <tr><td>Clé d'API Claude</td><td><?php echo key_ok($c['anthropic_key']) ? 'renseignée' : '<strong>à renseigner dans config.php</strong>'; ?></td></tr>
      <tr><td>Jeton Notion</td><td><?php echo key_ok($c['notion_token']) ? 'renseigné' : '<strong>à renseigner dans config.php</strong>'; ?></td></tr>
      <tr><td>Comptes</td><td><?php $na = 0; $nad = 0; foreach (antoine_accounts() as $ac) { if (empty($ac['active'])) continue; if ($ac['role'] === 'admin') $nad++; else $na++; } echo $nad . ' administrateur' . ($nad > 1 ? 's' : '') . ', ' . $na . ' compte' . ($na > 1 ? 's' : '') . ' agence actif' . ($na > 1 ? 's' : ''); ?></td></tr>
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
    <form method="post" style="margin-top:14px"><input type="hidden" name="csrf" value="<?php echo h(antoine_csrf()); ?>">
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
    <a class="btn" href="analyse.php">Ouvrir l'analyse de la base</a>
  </section>
  <section><h2>Cartographie des sujets manquants</h2>
    <p>Tableaux des sujets qui manquent à chaque produit, par marché et par catégorie, d'après l'état de la base.</p>
    <a class="btn" href="cartographie.php">Ouvrir la cartographie</a>
  </section>
  <section><h2>Cartographie du glossaire de traduction</h2>
    <p>Couverture du glossaire par section, terme, langue et source, et termes utilisés dans la base mais absents du glossaire.</p>
    <a class="btn" href="glossaire.php">Ouvrir la cartographie du glossaire</a>
  </section>
  <section><h2>Affirmations à confirmer</h2>
    <p>Affirmations déduites de la base, page par page : vous confirmez, infirmez ou précisez ; l'outil garde vos verdicts et signale ceux dont l'extrait de la base a changé.</p>
    <a class="btn" href="affirmations.php">Ouvrir les affirmations</a>
  </section>
  <section><h2>Comptes</h2>
    <p>Créer, désactiver ou modifier les comptes des agences et des administrateurs, et réinitialiser un mot de passe.</p>
    <a class="btn" href="comptes.php">Ouvrir la gestion des comptes</a>
  </section>
  <form method="post"><button class="btn secondary" name="logout" value="1">Se déconnecter</button></form>
<?php endif; ?>
<?php admin_shell_close(); ?></body></html>
