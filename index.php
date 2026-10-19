<?php
/* ElanCité Product AI — page de l'outil. */
define('ANTOINE', 1);
header('Content-Type: text/html; charset=utf-8');
header('Cache-Control: no-store');
header('X-Robots-Tag: noindex, nofollow');
header('X-Frame-Options: SAMEORIGIN');
$html = file_get_contents(__DIR__ . '/lib/app.html');
echo str_replace('/*ANTOINE_CONFIG*/', 'window.ANTOINE_CONFIG = {"api":"api.php"};', $html);
