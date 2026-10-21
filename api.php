<?php
/* ElanCité Product AI — API appelée par la page (index.php). */
define('ANTOINE', 1);
require __DIR__ . '/lib/core.php';
require __DIR__ . '/lib/notion.php';
require __DIR__ . '/lib/claude.php';
require __DIR__ . '/lib/prompts.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Robots-Tag: noindex, nofollow');

function antoine_out($data, $status = 200) { http_response_code($status); echo antoine_json($data); exit; }

/** Début d'une réponse en streaming : sans tampon ni compression, pour que chaque morceau parte aussitôt. */
function antoine_ia_stream_start() {
    ignore_user_abort(true); // l'appel à Claude s'arrête proprement (et sa consommation est comptée) si la page est fermée
    @ini_set('zlib.output_compression', '0');
    @ini_set('output_buffering', '0');
    if (function_exists('apache_setenv')) @apache_setenv('no-gzip', '1');
    while (ob_get_level() > 0) @ob_end_clean();
    header('Content-Type: text/event-stream; charset=utf-8');
    header('Cache-Control: no-store');
    header('X-Accel-Buffering: no');
    echo ': ' . str_repeat(' ', 2048) . "\n\n"; // remplissage : certains serveurs attendent quelques Ko avant d'envoyer
    flush();
}
/** Un événement SSE ; renvoie false si la page a été fermée. */
function antoine_ia_stream_event($data) {
    echo 'data: ' . antoine_json($data) . "\n\n";
    flush();
    return !connection_aborted();
}

try {
    $action = (string) ($_GET['action'] ?? '');
    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

    // Connexion (identifiant + mot de passe) : pose le cookie de session.
    if ($action === 'auth' && $method === 'POST') {
        $in = json_decode((string) file_get_contents('php://input'), true);
        if (!is_array($in)) throw new AntoineError('antoine_input', 'Requête illisible.', 400);
        $u = antoine_login($in['login'] ?? '', $in['password'] ?? '');
        antoine_out(['user' => $u, 'csrf' => antoine_csrf()]);
    }

    $user = antoine_session_user();

    // État de la session (au chargement de la page).
    if ($action === 'me' && $method === 'GET') {
        if ($user === null) throw new AntoineError('antoine_unauthorized', 'Session expirée ou absente.', 401);
        antoine_out(['user' => $user, 'csrf' => antoine_csrf()]);
    }

    if ($user === null) throw new AntoineError('antoine_unauthorized', 'Session expirée : reconnectez-vous.', 401);
    // Toute requête qui modifie quelque chose doit porter le jeton CSRF de la session.
    if ($method !== 'GET' && !antoine_csrf_ok($_SERVER['HTTP_X_ANTOINE_CSRF'] ?? '')) throw new AntoineError('antoine_csrf', 'Requête refusée (jeton de sécurité invalide). Rechargez la page.', 403);
    $agency = $user['agency'];

    if ($action === 'logout' && $method === 'POST') { antoine_logout(); antoine_out(['ok' => true]); }

    if ($action === 'kb' && $method === 'GET') {
        $kb = antoine_ia_load_kb(false);
        unset($kb['consignes']); // les consignes ne sont pas montrées aux agences
        antoine_out($kb);
    }

    if ($action === 'claude' && $method === 'POST') {
        @set_time_limit(300);
        $s = antoine_config();
        if (!$s['anthropic_key'] || strpos($s['anthropic_key'], 'REMPLACER') !== false) throw new AntoineError('antoine_config', "La clé d'API Claude n'est pas configurée.", 500);

        // Plafond mensuel
        $month = gmdate('Y-m');
        $usage = antoine_read('usage', []);
        if ((float) $s['monthly_budget'] > 0 && (float) ($usage[$month]['total'] ?? 0) >= (float) $s['monthly_budget']) {
            throw new AntoineError('antoine_budget', "Le budget mensuel de l'outil est atteint. Contactez ElanCité.", 429);
        }

        $p = json_decode((string) file_get_contents('php://input'), true);
        if (!is_array($p)) throw new AntoineError('antoine_input', 'Requête illisible.', 400);
        $kb = antoine_ia_load_kb(false);
        $kbText = antoine_ia_kb_text($kb, !empty($p['draft']));
        if (!$kbText['used_count']) throw new AntoineError('antoine_no_pages', "Aucune page produit n'est au statut « Validé ». Cochez « Utiliser aussi les pages à valider ».", 400);

        $built = antoine_ia_build_request((string) ($p['task'] ?? ''), $p, $kbText, $kb['consignes'] ?? null);
        // Limite horaire par agence
        $hour = gmdate('YmdH'); $limit = (int) $s['hourly_limit'];
        antoine_update('rate', [], function ($rl) use ($agency, $hour, $limit) {
            $rl = array_filter($rl, function ($k) use ($hour) { return substr($k, -10) === $hour; }, ARRAY_FILTER_USE_KEY);
            $k = $agency . '|' . $hour;
            if ($limit > 0 && ($rl[$k] ?? 0) >= $limit) throw new AntoineError('antoine_rate_limited', "Limite d'utilisation horaire atteinte pour votre agence. Réessayez dans l'heure.", 429);
            $rl[$k] = ($rl[$k] ?? 0) + 1;
            return $rl;
        });

        // Consommation mensuelle, par agence
        $record = function ($cost) use ($month, $agency) {
            antoine_update('usage', [], function ($u) use ($month, $agency, $cost) {
                if (!isset($u[$month])) $u[$month] = ['total' => 0, 'agencies' => []];
                $u[$month]['total'] = round($u[$month]['total'] + $cost, 6);
                $a = $u[$month]['agencies'][$agency] ?? ['calls' => 0, 'cost' => 0];
                $u[$month]['agencies'][$agency] = ['calls' => $a['calls'] + 1, 'cost' => round($a['cost'] + $cost, 6)];
                krsort($u);
                return array_slice($u, 0, 12, true);
            });
        };

        // Questions : réponse envoyée au fil de l'eau (événements SSE « delta », puis « done » ou « error »)
        if (($p['task'] ?? '') === 'ask' && !empty($p['stream'])) {
            antoine_ia_stream_start();
            [$text, $cost, $err] = antoine_ia_claude_stream($kbText['text'], $built['instructions'], $built['messages'], $s,
                function ($t) { return antoine_ia_stream_event(['type' => 'delta', 'text' => $t]); });
            $record($cost);
            if ($err) antoine_ia_stream_event(['type' => 'error', 'code' => $err->key, 'message' => $err->getMessage()]);
            else antoine_ia_stream_event(['type' => 'done', 'used' => $kbText['used'], 'excluded' => $kbText['excluded']]);
            exit;
        }

        $res = antoine_ia_claude_call($kbText['text'], $built['instructions'], $built['messages'], $s);
        $record($res['cost']);

        if ($built['json']) {
            $data = antoine_ia_parse_json($res['text']);
            if ($data === null) throw new AntoineError('antoine_invalid_json', "La réponse de Claude n'a pas pu être lue. Relancez ; si le problème persiste, découpez le contenu.", 502);
            antoine_out(['data' => $data, 'used' => $kbText['used'], 'excluded' => $kbText['excluded']]);
        }
        antoine_out(['text' => $res['text'], 'used' => $kbText['used'], 'excluded' => $kbText['excluded']]);
    }

    throw new AntoineError('antoine_input', 'Action inconnue.', 404);
} catch (AntoineError $e) {
    antoine_out(['code' => $e->key, 'message' => $e->getMessage()], $e->status);
} catch (Throwable $e) {
    error_log('ElanCité Product AI : ' . $e->getMessage());
    antoine_out(['code' => 'antoine_server', 'message' => 'Erreur interne du serveur. Réessayez ; si le problème persiste, contactez ElanCité.'], 500);
}
