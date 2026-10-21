<?php
if (!defined('ANTOINE')) { http_response_code(404); exit; }

/*
 * Authentification unique : comptes nominatifs (identifiant + mot de passe), rôles « admin » et « agence ».
 *
 * Stockage (dossier data/, illisible depuis le web) :
 *   accounts : identifiant => [login, name, role, hash, active, created, updated]
 *   sessions : empreinte SHA-256 du jeton => [login, created, last, expires, recovery]
 *   logins   : compteurs d'échecs de connexion (limitation des essais)
 * Le jeton de session n'est jamais stocké en clair ; il est dans un cookie HttpOnly.
 * Au premier appel (fichier accounts absent), les comptes sont créés depuis config.php :
 * un compte « admin » (mot de passe = admin_password) et un compte par agence
 * (identifiant = nom de l'agence en minuscules sans accents, mot de passe = son ancien code d'accès).
 */

const ANTOINE_COOKIE      = 'antoine_sid';
const ANTOINE_SESSION_DAYS = 14;
const ANTOINE_LOGIN_MAX_FAILS = 5;     // échecs tolérés…
const ANTOINE_LOGIN_WINDOW    = 900;   // …sur cette durée (secondes)

function antoine_login_valid($login) { return is_string($login) && preg_match('/^[a-z0-9]{3,32}$/', $login) === 1; }

/** Nom d'agence → identifiant (lettres et chiffres, minuscules, sans accents). */
function antoine_slug_login($name) {
    $s = (string) $name;
    if (function_exists('iconv')) { $t = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $s); if ($t !== false && $t !== '') $s = $t; }
    $s = strtolower(preg_replace('/[^A-Za-z0-9]+/', '', $s));
    if (strlen($s) < 3) $s = str_pad($s, 3, '0');
    return substr($s, 0, 32);
}

function antoine_admin_password_configured() {
    $p = (string) (antoine_config()['admin_password'] ?? '');
    return strlen($p) >= 12 && strpos($p, 'REMPLACER') === false;
}

/** Comptes initiaux issus de config.php (création unique). */
function antoine_accounts_seed() {
    $c = antoine_config(); $now = gmdate('c'); $acc = [];
    if (antoine_admin_password_configured()) {
        $acc['admin'] = ['login' => 'admin', 'name' => 'ElanCité', 'role' => 'admin', 'hash' => password_hash((string) $c['admin_password'], PASSWORD_DEFAULT), 'active' => true, 'created' => $now, 'updated' => $now];
    }
    foreach ((array) ($c['agencies'] ?? []) as $name => $code) {
        if (!is_string($code) || strlen($code) < 12 || strpos($code, 'REMPLACER') !== false) continue;
        $login = antoine_slug_login($name); $base = $login; $i = 2;
        while (isset($acc[$login])) { $login = substr($base, 0, 30) . $i++; }
        $acc[$login] = ['login' => $login, 'name' => (string) $name, 'role' => 'agency', 'hash' => password_hash($code, PASSWORD_DEFAULT), 'active' => true, 'created' => $now, 'updated' => $now];
    }
    return $acc;
}

/** Tous les comptes, indexés par identifiant (création depuis config.php au premier appel). */
function antoine_accounts() {
    static $cache = null;
    if ($cache !== null) return $cache;
    if (!is_file(antoine_data_path('accounts'))) {
        antoine_update('accounts', [], function ($a) { return is_file(antoine_data_path('accounts')) ? $a : antoine_accounts_seed(); });
    }
    return $cache = antoine_read('accounts', []);
}

function antoine_has_active_admin() {
    foreach (antoine_accounts() as $a) if (!empty($a['active']) && ($a['role'] ?? '') === 'admin') return true;
    return false;
}

function antoine_is_https() {
    return (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || strtolower((string) ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https';
}
function antoine_cookie_path() { $d = rtrim(str_replace('\\', '/', dirname((string) ($_SERVER['SCRIPT_NAME'] ?? '/'))), '/'); return $d . '/'; }
function antoine_set_cookie($value, $expires) {
    if (headers_sent()) return;
    header('Set-Cookie: ' . ANTOINE_COOKIE . '=' . $value . '; Expires=' . gmdate('D, d M Y H:i:s', $expires) . ' GMT; Max-Age=' . max(0, $expires - time())
        . '; Path=' . antoine_cookie_path() . '; HttpOnly; SameSite=Lax' . (antoine_is_https() ? '; Secure' : ''), false);
}
function antoine_session_token() {
    $t = (string) ($_COOKIE[ANTOINE_COOKIE] ?? '');
    return preg_match('/^[a-f0-9]{64}$/', $t) ? $t : '';
}

/** Représentation d'un compte pour l'application (jamais le hash). */
function antoine_user_public($a, $recovery = false) {
    return ['login' => $a['login'], 'name' => $a['name'], 'role' => $a['role'], 'agency' => $a['name'], 'recovery' => $recovery];
}

/** Limitation des essais : refuse après trop d'échecs récents pour ce couple IP + identifiant. */
function antoine_login_throttle_key($login) { return hash('sha256', (string) ($_SERVER['REMOTE_ADDR'] ?? '') . '|' . $login); }
function antoine_login_check_throttle($login) {
    $k = antoine_login_throttle_key($login); $l = antoine_read('logins', []);
    $e = $l[$k] ?? null;
    if ($e && $e['first'] > time() - ANTOINE_LOGIN_WINDOW && $e['count'] >= ANTOINE_LOGIN_MAX_FAILS) {
        throw new AntoineError('antoine_throttled', 'Trop de tentatives. Réessayez dans quelques minutes.', 429);
    }
}
function antoine_login_record_failure($login) {
    $k = antoine_login_throttle_key($login);
    antoine_update('logins', [], function ($l) use ($k) {
        $now = time();
        foreach ($l as $kk => $e) if ($e['first'] < $now - ANTOINE_LOGIN_WINDOW) unset($l[$kk]);
        if (!isset($l[$k])) $l[$k] = ['first' => $now, 'count' => 0];
        $l[$k]['count']++;
        return $l;
    });
}
function antoine_login_clear_failures($login) {
    $k = antoine_login_throttle_key($login);
    $l = antoine_read('logins', []);
    if (isset($l[$k])) antoine_update('logins', [], function ($l) use ($k) { unset($l[$k]); return $l; });
}

/** Connexion : renvoie l'utilisateur et pose le cookie de session, ou lève une AntoineError. */
function antoine_login($login, $password) {
    $login = strtolower(trim((string) $login)); $password = (string) $password;
    usleep(400000); // freine les essais en série
    if (!antoine_login_valid($login) || $password === '' || strlen($password) > 1024) {
        throw new AntoineError('antoine_unauthorized', 'Identifiant ou mot de passe incorrect.', 401);
    }
    antoine_login_check_throttle($login);
    $acc = antoine_accounts()[$login] ?? null;
    $user = null; $recovery = false;
    if ($acc && !empty($acc['active']) && password_verify($password, (string) $acc['hash'])) {
        $user = antoine_user_public($acc);
    } elseif ($login === 'admin' && !antoine_has_active_admin() && antoine_admin_password_configured()
              && hash_equals((string) antoine_config()['admin_password'], $password)) {
        // Secours : aucun administrateur actif, le mot de passe de config.php ouvre le compte « admin ».
        $recovery = true;
        $user = ['login' => 'admin', 'name' => 'ElanCité', 'role' => 'admin', 'agency' => 'ElanCité', 'recovery' => true];
    }
    if ($user === null) {
        antoine_login_record_failure($login);
        throw new AntoineError('antoine_unauthorized', 'Identifiant ou mot de passe incorrect.', 401);
    }
    antoine_login_clear_failures($login);
    $token = bin2hex(random_bytes(32)); $h = hash('sha256', $token); $now = time(); $exp = $now + ANTOINE_SESSION_DAYS * 86400;
    antoine_update('sessions', [], function ($s) use ($h, $login, $now, $exp, $recovery) {
        foreach ($s as $k => $v) if (($v['expires'] ?? 0) < $now) unset($s[$k]);
        $s[$h] = ['login' => $login, 'created' => $now, 'last' => $now, 'expires' => $exp, 'recovery' => $recovery];
        return $s;
    });
    antoine_set_cookie($token, $exp);
    $_COOKIE[ANTOINE_COOKIE] = $token;
    return $user;
}

/** Utilisateur de la session en cours (compte actif), ou null. */
function antoine_session_user() {
    static $done = false, $user = null;
    if ($done) return $user;
    $done = true;
    $token = antoine_session_token();
    if ($token === '') return null;
    $h = hash('sha256', $token); $now = time();
    $s = antoine_read('sessions', [])[$h] ?? null;
    if (!$s || ($s['expires'] ?? 0) < $now) return null;
    if (!empty($s['recovery'])) {
        if (antoine_has_active_admin() || !antoine_admin_password_configured()) return null;
        $user = ['login' => 'admin', 'name' => 'ElanCité', 'role' => 'admin', 'agency' => 'ElanCité', 'recovery' => true];
    } else {
        $acc = antoine_accounts()[$s['login']] ?? null;
        if (!$acc || empty($acc['active'])) return null;
        $user = antoine_user_public($acc);
    }
    if (($s['last'] ?? 0) < $now - 3600) { // session glissante : prolongée au plus une fois par heure
        $exp = $now + ANTOINE_SESSION_DAYS * 86400;
        antoine_update('sessions', [], function ($all) use ($h, $now, $exp) { if (isset($all[$h])) { $all[$h]['last'] = $now; $all[$h]['expires'] = $exp; } return $all; });
        antoine_set_cookie($token, $exp);
    }
    return $user;
}

/** Utilisateur connecté s'il est administrateur, sinon null. */
function antoine_admin_user() { $u = antoine_session_user(); return ($u && $u['role'] === 'admin') ? $u : null; }

/** Jeton CSRF lié à la session (formulaires : champ « csrf » ; API : en-tête X-Antoine-Csrf). */
function antoine_csrf() {
    $t = antoine_session_token();
    return $t === '' ? '' : substr(hash_hmac('sha256', 'csrf', $t), 0, 32);
}
function antoine_csrf_ok($given) { $c = antoine_csrf(); return $c !== '' && hash_equals($c, (string) $given); }

function antoine_logout() {
    $t = antoine_session_token();
    if ($t !== '') { $h = hash('sha256', $t); antoine_update('sessions', [], function ($s) use ($h) { unset($s[$h]); return $s; }); }
    antoine_set_cookie('deleted', time() - 86400);
    unset($_COOKIE[ANTOINE_COOKIE]);
}

/* ---------- Gestion des comptes (page « Comptes ») ---------- */

/** Mot de passe aléatoire lisible (sans caractères ambigus), 16 caractères. */
function antoine_generate_password() {
    $alpha = 'abcdefghijkmnpqrstuvwxyzABCDEFGHJKLMNPQRSTUVWXYZ23456789'; $n = strlen($alpha); $p = '';
    for ($i = 0; $i < 16; $i++) $p .= $alpha[random_int(0, $n - 1)];
    return $p;
}

/** Ferme toutes les sessions d'un compte. */
function antoine_sessions_revoke($login) {
    antoine_update('sessions', [], function ($s) use ($login) {
        foreach ($s as $k => $v) if (($v['login'] ?? '') === $login) unset($s[$k]);
        return $s;
    });
}

function antoine_count_active_admins($accounts) {
    $n = 0; foreach ($accounts as $a) if (!empty($a['active']) && $a['role'] === 'admin') $n++;
    return $n;
}
function antoine_account_name_check($name, $accounts, $exceptLogin = '') {
    $name = trim(preg_replace('/\s+/', ' ', (string) $name));
    if ($name === '' || mb_strlen($name) > 60) throw new AntoineError('antoine_input', "Le nom doit comporter de 1 à 60 caractères.", 400);
    foreach ($accounts as $l => $a) if ($l !== $exceptLogin && mb_strtolower($a['name']) === mb_strtolower($name)) throw new AntoineError('antoine_input', "Ce nom est déjà utilisé par le compte « $l ».", 400);
    return $name;
}

/** Crée un compte ; renvoie [login, mot de passe généré]. */
function antoine_account_create($login, $name, $role) {
    $login = strtolower(trim((string) $login));
    if (!antoine_login_valid($login)) throw new AntoineError('antoine_input', "Identifiant invalide : 3 à 32 lettres ou chiffres, sans accents ni espaces.", 400);
    if (!in_array($role, ['admin', 'agency'], true)) throw new AntoineError('antoine_input', 'Rôle inconnu.', 400);
    $pwd = antoine_generate_password();
    antoine_update('accounts', [], function ($acc) use ($login, $name, $role, $pwd) {
        if (isset($acc[$login])) throw new AntoineError('antoine_input', "L'identifiant « $login » existe déjà.", 400);
        $name = antoine_account_name_check($name, $acc);
        $now = gmdate('c');
        $acc[$login] = ['login' => $login, 'name' => $name, 'role' => $role, 'hash' => password_hash($pwd, PASSWORD_DEFAULT), 'active' => true, 'created' => $now, 'updated' => $now];
        return $acc;
    });
    return [$login, $pwd];
}

/** Modifie nom, rôle ou état d'un compte, en gardant toujours un administrateur actif. $self = identifiant de l'appelant. */
function antoine_account_update($login, array $changes, $self) {
    antoine_update('accounts', [], function ($acc) use ($login, $changes, $self) {
        if (!isset($acc[$login])) throw new AntoineError('antoine_input', 'Compte introuvable.', 404);
        $a = $acc[$login];
        if (isset($changes['name'])) $a['name'] = antoine_account_name_check($changes['name'], $acc, $login);
        if (isset($changes['role'])) {
            if (!in_array($changes['role'], ['admin', 'agency'], true)) throw new AntoineError('antoine_input', 'Rôle inconnu.', 400);
            $a['role'] = $changes['role'];
        }
        if (isset($changes['active'])) $a['active'] = (bool) $changes['active'];
        if ($login === $self && (empty($a['active']) || $a['role'] !== 'admin')) throw new AntoineError('antoine_input', 'Vous ne pouvez pas désactiver ni rétrograder votre propre compte.', 400);
        $a['updated'] = gmdate('c');
        $acc[$login] = $a;
        if (antoine_count_active_admins($acc) < 1) throw new AntoineError('antoine_input', 'Il doit rester au moins un administrateur actif.', 400);
        return $acc;
    });
    if (isset($changes['active']) && !$changes['active']) antoine_sessions_revoke($login);
}

/** Nouveau mot de passe généré ; ferme les sessions du compte. */
function antoine_account_reset_password($login) {
    $pwd = antoine_generate_password();
    antoine_update('accounts', [], function ($acc) use ($login, $pwd) {
        if (!isset($acc[$login])) throw new AntoineError('antoine_input', 'Compte introuvable.', 404);
        $acc[$login]['hash'] = password_hash($pwd, PASSWORD_DEFAULT);
        $acc[$login]['updated'] = gmdate('c');
        return $acc;
    });
    antoine_sessions_revoke($login);
    return $pwd;
}
