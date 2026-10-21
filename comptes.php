<?php
/* ElanCité Product AI — administration : comptes des agences et des administrateurs. */
define('ANTOINE', 1);
require __DIR__ . '/lib/core.php';
require __DIR__ . '/lib/admin_ui.php';

header('X-Robots-Tag: noindex, nofollow');
header('Cache-Control: no-store');

$user = antoine_admin_user();
if ($user === null) { header('Location: admin.php'); exit; }
$csrf = antoine_csrf();
function h($s) { return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8'); }

$msg = ''; $ok = true; $secret = null; // $secret = [identifiant, mot de passe] affiché une seule fois

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    try {
        if (!antoine_csrf_ok($_POST['csrf'] ?? '')) throw new AntoineError('antoine_csrf', 'Requête refusée (jeton de sécurité invalide). Rechargez la page.', 403);
        $op = (string) ($_POST['op'] ?? ''); $login = strtolower((string) ($_POST['login'] ?? ''));
        if ($op === 'create') {
            $secret = antoine_account_create($login, $_POST['name'] ?? '', (string) ($_POST['role'] ?? 'agency'));
            $msg = "Compte « {$secret[0]} » créé.";
        } elseif ($op === 'save') {
            antoine_account_update($login, ['name' => $_POST['name'] ?? '', 'role' => (string) ($_POST['role'] ?? '')], $user['login']);
            $msg = "Compte « $login » modifié.";
        } elseif ($op === 'deactivate' || $op === 'activate') {
            antoine_account_update($login, ['active' => $op === 'activate'], $user['login']);
            $msg = "Compte « $login » " . ($op === 'activate' ? 'réactivé.' : 'désactivé : ses sessions sont fermées.');
        } elseif ($op === 'reset') {
            $secret = [$login, antoine_account_reset_password($login)];
            $msg = "Nouveau mot de passe généré pour « $login » : ses sessions sont fermées.";
        } else throw new AntoineError('antoine_input', 'Action inconnue.', 400);
    } catch (AntoineError $e) { $msg = $e->getMessage(); $ok = false; http_response_code($e->status >= 500 ? 500 : 200); }
}
// Relecture directe : le cache de antoine_accounts() date d'avant une éventuelle écriture.
$accounts = antoine_read('accounts', []);
uasort($accounts, function ($a, $b) { return strcmp(($a['role'] === 'admin' ? '0' : '1') . mb_strtolower($a['name']), ($b['role'] === 'admin' ? '0' : '1') . mb_strtolower($b['name'])); });
$roles = ['admin' => 'Administrateur', 'agency' => 'Agence'];
?><!DOCTYPE html>
<html lang="fr"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title>ElanCité Product AI — comptes</title>
<link rel="icon" href="favicon.ico" sizes="any">
<link rel="icon" href="favicon.svg" type="image/svg+xml">
<link rel="apple-touch-icon" href="apple-touch-icon.png">
<?php echo admin_head_links(); ?>
<style>
table{border-collapse:collapse;width:100%;font-size:14px}
td,th{border-bottom:1px solid var(--line);padding:6px 8px;text-align:left;vertical-align:middle}
td.off{opacity:.55}
.inline{display:inline}
.grid{display:grid;gap:12px;max-width:420px}
.grid label{display:grid;gap:4px}
.secret{font-family:ui-monospace,Menlo,Consolas,monospace;font-size:16px;padding:8px 10px;border:1px dashed var(--line);border-radius:6px;display:inline-block;user-select:all}
.row-form input[type=text]{width:100%;min-width:120px}
</style></head><body>
<?php admin_shell_open('comptes', 'Comptes', true); ?>
<?php if ($msg): ?><div class="msg<?php echo $ok ? '' : ' err'; ?>"><?php echo h($msg); ?></div><?php endif; ?>
<?php if ($secret): ?>
  <section><h2>À transmettre maintenant</h2>
    <p>Identifiant : <span class="secret"><?php echo h($secret[0]); ?></span> &nbsp; Mot de passe : <span class="secret"><?php echo h($secret[1]); ?></span></p>
    <p class="hint">Ce mot de passe n'est affiché qu'une fois et n'est pas conservé en clair : notez-le avant de quitter la page. Transmettez-le par un canal sûr ; en cas de perte, générez-en un nouveau.</p>
  </section>
<?php endif; ?>
<section><h2>Comptes</h2>
  <table>
    <thead><tr><th>Identifiant</th><th>Nom</th><th>Rôle</th><th>État</th><th></th></tr></thead><tbody>
    <?php foreach ($accounts as $l => $a): $self = $l === $user['login']; $off = empty($a['active']); ?>
      <tr>
        <td class="<?php echo $off ? 'off' : ''; ?>"><strong><?php echo h($l); ?></strong><?php echo $self ? ' <span class="hint">(vous)</span>' : ''; ?></td>
        <td colspan="2" class="<?php echo $off ? 'off' : ''; ?>">
          <form method="post" class="row-form" style="display:flex;gap:8px;align-items:center">
            <input type="hidden" name="csrf" value="<?php echo h($csrf); ?>"><input type="hidden" name="op" value="save"><input type="hidden" name="login" value="<?php echo h($l); ?>">
            <input type="text" name="name" value="<?php echo h($a['name']); ?>" maxlength="60" aria-label="Nom de <?php echo h($l); ?>">
            <select name="role" aria-label="Rôle de <?php echo h($l); ?>"<?php echo $self ? ' disabled' : ''; ?>>
              <?php foreach ($roles as $rk => $rn): ?><option value="<?php echo $rk; ?>"<?php echo $a['role'] === $rk ? ' selected' : ''; ?>><?php echo h($rn); ?></option><?php endforeach; ?>
            </select>
            <?php if ($self): ?><input type="hidden" name="role" value="<?php echo h($a['role']); ?>"><?php endif; ?>
            <button class="btn secondary">Enregistrer</button>
          </form>
        </td>
        <td><?php echo $off ? 'Désactivé' : 'Actif'; ?></td>
        <td style="white-space:nowrap">
          <form method="post" class="inline"><input type="hidden" name="csrf" value="<?php echo h($csrf); ?>"><input type="hidden" name="login" value="<?php echo h($l); ?>">
            <button class="btn secondary" name="op" value="reset" onclick="return confirm('Générer un nouveau mot de passe pour <?php echo h($l); ?> ? L\'ancien cessera de fonctionner.');">Nouveau mot de passe</button>
            <?php if (!$self): ?><button class="btn secondary" name="op" value="<?php echo $off ? 'activate' : 'deactivate'; ?>"><?php echo $off ? 'Réactiver' : 'Désactiver'; ?></button><?php endif; ?>
          </form>
        </td>
      </tr>
    <?php endforeach; ?></tbody></table>
  <p class="hint">Les agences utilisent l'outil de relecture ; seuls les administrateurs voient l'administration. Le nom d'un compte agence sert au suivi de la consommation.</p>
</section>
<section><h2>Créer un compte</h2>
  <form method="post" class="grid">
    <input type="hidden" name="csrf" value="<?php echo h($csrf); ?>"><input type="hidden" name="op" value="create">
    <label>Identifiant <span class="hint">3 à 32 lettres ou chiffres, sans accents ni espaces</span>
      <input type="text" name="login" maxlength="32" pattern="[A-Za-z0-9]{3,32}" autocapitalize="none" spellcheck="false" autocomplete="off" required></label>
    <label>Nom <span class="hint">nom de l'agence ou de la personne</span>
      <input type="text" name="name" maxlength="60" autocomplete="off" required></label>
    <label>Rôle
      <select name="role"><option value="agency">Agence</option><option value="admin">Administrateur</option></select></label>
    <button class="btn">Créer le compte</button>
    <span class="hint">Un mot de passe est généré et affiché une seule fois.</span>
  </form>
</section>
<?php admin_shell_close(); ?></body></html>
