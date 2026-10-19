<?php
if (!defined('ANTOINE')) { http_response_code(404); exit; }

class AntoineError extends Exception {
    public $key; public $status;
    function __construct($key, $message, $status = 400) { parent::__construct($message); $this->key = $key; $this->status = $status; }
}

if (!function_exists('mb_substr')) { function mb_substr($s, $a, $l = null) { return $l === null ? substr($s, $a) : substr($s, $a, $l); } }
if (!function_exists('mb_strlen')) { function mb_strlen($s) { return strlen(utf8_decode($s)); } }

function antoine_config() {
    static $c = null;
    if ($c === null) $c = require __DIR__ . '/../config.php';
    return $c;
}

function antoine_json($v) { return json_encode($v, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); }

/** Requête HTTP (cURL). Renvoie [code, corps décodé]. */
function antoine_http($method, $url, $headers, $body, $timeout) {
    if (!function_exists('curl_init')) {
        // Repli sans cURL
        $h = '';
        foreach ($headers as $k => $v) $h .= $k . ': ' . $v . "\r\n";
        $ctx = stream_context_create(['http' => ['method' => $method, 'header' => $h, 'content' => $body !== null ? antoine_json($body) : null, 'timeout' => $timeout, 'ignore_errors' => true]]);
        $raw = @file_get_contents($url, false, $ctx);
        if ($raw === false) return [0, null, 'connexion impossible'];
        $code = 0;
        foreach ($http_response_header ?? [] as $line) if (preg_match('#^HTTP/\S+\s+(\d{3})#', $line, $m)) $code = (int) $m[1];
        return [$code, json_decode($raw, true), ''];
    }
    $ch = curl_init($url);
    $h = [];
    foreach ($headers as $k => $v) $h[] = $k . ': ' . $v;
    curl_setopt_array($ch, [
        CURLOPT_CUSTOMREQUEST  => $method,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER     => $h,
        CURLOPT_TIMEOUT        => $timeout,
        CURLOPT_CONNECTTIMEOUT => 15,
    ]);
    if ($body !== null) curl_setopt($ch, CURLOPT_POSTFIELDS, antoine_json($body));
    $raw = curl_exec($ch);
    if ($raw === false) { $err = curl_error($ch); curl_close($ch); return [0, null, $err]; }
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return [$code, json_decode($raw, true), ''];
}

/*
 * Stockage dans data/ : chaque fichier commence par « <?php exit; ?> »,
 * ce qui le rend illisible depuis le web quel que soit le serveur.
 */
function antoine_data_path($name) {
    $dir = __DIR__ . '/../data';
    if (!is_dir($dir)) @mkdir($dir, 0750, true);
    return $dir . '/' . $name . '.php';
}
function antoine_read($name, $default) {
    $f = antoine_data_path($name);
    if (!is_file($f)) return $default;
    $raw = (string) @file_get_contents($f);
    $data = json_decode(substr($raw, strlen("<?php exit; ?>\n")), true);
    return is_array($data) ? $data : $default;
}
function antoine_write($name, $data) {
    $f = antoine_data_path($name);
    if (@file_put_contents($f, "<?php exit; ?>\n" . antoine_json($data), LOCK_EX) === false) {
        throw new AntoineError('antoine_storage', "Écriture impossible dans le dossier data/ : vérifiez ses droits.", 500);
    }
}
/** Lecture-modification-écriture protégée par un verrou (compteurs, consommation). */
function antoine_update($name, $default, callable $fn) {
    $lock = fopen(antoine_data_path($name . '.lock'), 'c');
    flock($lock, LOCK_EX);
    try { $data = $fn(antoine_read($name, $default)); antoine_write($name, $data); return $data; }
    finally { flock($lock, LOCK_UN); fclose($lock); }
}

/** Agence correspondant au code reçu dans l'en-tête X-Antoine-Code, ou null. */
function antoine_agency() {
    $code = (string) ($_SERVER['HTTP_X_ANTOINE_CODE'] ?? '');
    if ($code === '') return null;
    foreach (antoine_config()['agencies'] as $name => $c) {
        if (is_string($c) && strlen($c) >= 12 && hash_equals($c, $code)) return (string) $name;
    }
    return null;
}
