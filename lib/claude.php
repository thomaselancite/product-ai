<?php
if (!defined('ANTOINE')) { http_response_code(404); exit; }

/** Corps et en-têtes de la requête : base de connaissance en tête du prompt système, mise en cache, puis consignes. */
function antoine_ia_claude_request($kbText, $instructions, $messages, $s) {
    $body = [
        'model'      => $s['model'],
        'max_tokens' => (int) $s['max_tokens'],   // couvre la réflexion et la réponse
        'system'     => [
            ['type' => 'text', 'text' => $kbText, 'cache_control' => ['type' => 'ephemeral']],
            ['type' => 'text', 'text' => $instructions],
        ],
        'messages'   => $messages,
    ];
    if (!empty($s['effort'])) $body['output_config'] = ['effort' => $s['effort']];

    $headers = [
        'x-api-key'         => $s['anthropic_key'],
        'anthropic-version' => '2023-06-01',
        'content-type'      => 'application/json',
    ];
    // Nécessaire si la clé d'API n'est pas rattachée à un espace de travail (workspace)
    if (!empty($s['anthropic_workspace'])) $headers['anthropic-workspace-id'] = $s['anthropic_workspace'];
    return [$body, $headers];
}

/** Erreur renvoyée par l'API (code HTTP et corps décodé), traduite pour les agences. */
function antoine_ia_claude_error($code, $json) {
    if ($code === 429 || $code === 529) return new AntoineError('antoine_claude_busy', 'Claude est très sollicité. Patientez une minute, puis relancez.', 503);
    return new AntoineError('antoine_claude', 'Claude a refusé la demande : ' . ($json['error']['message'] ?? ('erreur ' . $code)), 502);
}

/**
 * Appelle l'API Messages de Claude.
 * La base de connaissance est placée en tête du prompt système et mise en cache
 * (elle est identique d'un appel à l'autre), puis viennent les consignes de la tâche.
 */
function antoine_ia_claude_call($kbText, $instructions, $messages, $s) {
    [$body, $headers] = antoine_ia_claude_request($kbText, $instructions, $messages, $s);
    [$code, $json, $err] = antoine_http('POST', (antoine_config()['claude_api'] ?? 'https://api.anthropic.com') . '/v1/messages', $headers, $body, 280);

    if ($code === 0) throw new AntoineError('antoine_claude', "Claude n'a pas répondu à temps ou est injoignable (" . $err . '). Relancez dans quelques instants.', 504);
    if ($code >= 300) throw antoine_ia_claude_error($code, $json);
    if (($json['stop_reason'] ?? '') === 'refusal') throw new AntoineError('antoine_refusal', 'Claude a décliné cette demande. Reformulez-la ou contactez Elan Cité.', 422);

    // Les blocs de réflexion sont ignorés : seul le texte compte
    $text = '';
    foreach ($json['content'] ?? [] as $block) if (($block['type'] ?? '') === 'text') $text .= $block['text'];
    if (($json['stop_reason'] ?? '') === 'max_tokens' && $text === '') {
        throw new AntoineError('antoine_too_long', 'La réponse a dépassé la longueur maximale. Découpez le contenu en parties plus courtes.', 502);
    }
    return ['text' => $text, 'cost' => antoine_ia_cost($json['usage'] ?? [], $s)];
}

/**
 * Même appel en streaming (SSE) : $onText reçoit chaque morceau de texte dès qu'il arrive.
 * $onText renvoie false quand la page a été fermée : l'appel est alors interrompu.
 * Renvoie toujours [texte, coût, erreur] : le coût des jetons déjà consommés est compté même en cas d'erreur.
 * Sans cURL, l'appel se fait sans streaming et le texte arrive en une fois.
 * $stopReason (facultatif) reçoit la raison de fin de réponse.
 */
function antoine_ia_claude_stream($kbText, $instructions, $messages, $s, callable $onText, &$stopReason = null) {
    if (!function_exists('curl_init')) {
        try { $r = antoine_ia_claude_call($kbText, $instructions, $messages, $s); }
        catch (AntoineError $e) { return ['', 0, $e]; }
        $onText($r['text']);
        return [$r['text'], $r['cost'], null];
    }
    [$body, $headers] = antoine_ia_claude_request($kbText, $instructions, $messages, $s);
    $body['stream'] = true;
    $h = [];
    foreach ($headers as $k => $v) $h[] = $k . ': ' . $v;

    $status = 0; $raw = ''; $buf = ''; $text = ''; $usage = []; $stop = ''; $sseErr = null; $aborted = false;
    $ch = curl_init((antoine_config()['claude_api'] ?? 'https://api.anthropic.com') . '/v1/messages');
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => antoine_json($body),
        CURLOPT_HTTPHEADER     => $h,
        CURLOPT_TIMEOUT        => 280,
        CURLOPT_CONNECTTIMEOUT => 15,
        CURLOPT_HEADERFUNCTION => function ($ch, $line) use (&$status) {
            if (preg_match('#^HTTP/\S+\s+(\d{3})#', $line, $m)) $status = (int) $m[1];
            return strlen($line);
        },
        CURLOPT_WRITEFUNCTION  => function ($ch, $chunk) use (&$status, &$raw, &$buf, &$text, &$usage, &$stop, &$sseErr, &$aborted, $onText) {
            if ($status >= 300) { $raw .= $chunk; return strlen($chunk); }
            $buf .= str_replace("\r", '', $chunk);
            while (($p = strpos($buf, "\n\n")) !== false) {
                $event = substr($buf, 0, $p); $buf = substr($buf, $p + 2);
                $data = '';
                foreach (explode("\n", $event) as $l) if (strncmp($l, 'data:', 5) === 0) $data .= ltrim(substr($l, 5));
                $e = $data !== '' ? json_decode($data, true) : null;
                if (!is_array($e)) continue;
                $type = $e['type'] ?? '';
                if ($type === 'message_start') $usage = (array) ($e['message']['usage'] ?? []);
                elseif ($type === 'content_block_delta' && ($e['delta']['type'] ?? '') === 'text_delta') {
                    $t = (string) ($e['delta']['text'] ?? '');
                    $text .= $t;
                    if ($t !== '' && $onText($t) === false) { $aborted = true; return 0; } // page fermée : on arrête Claude
                }
                elseif ($type === 'message_delta') {
                    if (isset($e['usage']['output_tokens'])) $usage['output_tokens'] = $e['usage']['output_tokens'];
                    if (!empty($e['delta']['stop_reason'])) $stop = $e['delta']['stop_reason'];
                }
                elseif ($type === 'error') $sseErr = $e['error'] ?? [];
                // Les blocs de réflexion (thinking) sont ignorés : seul le texte compte
            }
            return strlen($chunk);
        },
    ]);
    $ok = curl_exec($ch);
    $err = $ok === false ? curl_error($ch) : '';
    curl_close($ch);
    $cost = antoine_ia_cost($usage, $s);
    $stopReason = $stop; // fin de réponse annoncée par Claude (end_turn, max_tokens…) : l'appelant peut la consulter

    if ($aborted) return [$text, $cost, new AntoineError('antoine_cancelled', 'Réponse interrompue.', 499)];
    if ($status >= 300) return [$text, $cost, antoine_ia_claude_error($status, json_decode($raw, true))];
    if ($ok === false) return [$text, $cost, new AntoineError('antoine_claude', "Claude n'a pas répondu à temps ou est injoignable (" . $err . '). Relancez dans quelques instants.', 504)];
    if ($sseErr !== null) {
        $busy = in_array($sseErr['type'] ?? '', ['overloaded_error', 'rate_limit_error'], true);
        return [$text, $cost, $busy ? antoine_ia_claude_error(529, null) : new AntoineError('antoine_claude', 'Claude a interrompu la réponse : ' . ($sseErr['message'] ?? 'erreur'), 502)];
    }
    if ($stop === 'refusal') return [$text, $cost, new AntoineError('antoine_refusal', 'Claude a décliné cette demande. Reformulez-la ou contactez Elan Cité.', 422)];
    if ($stop === 'max_tokens' && $text === '') return [$text, $cost, new AntoineError('antoine_too_long', 'La réponse a dépassé la longueur maximale. Découpez le contenu en parties plus courtes.', 502)];
    return [$text, $cost, null];
}

/** Coût en dollars d'un appel, à partir des jetons consommés et des tarifs de config.php. */
function antoine_ia_cost($u, $s) {
    $in  = (float) $s['price_in'] / 1000000;
    $out = (float) $s['price_out'] / 1000000;
    return ($u['input_tokens'] ?? 0) * $in
         + ($u['cache_creation_input_tokens'] ?? 0) * $in * (float) $s['cache_write_mult']
         + ($u['cache_read_input_tokens'] ?? 0) * $in * (float) $s['cache_read_mult']
         + ($u['output_tokens'] ?? 0) * $out;
}

function antoine_ia_parse_json($text) {
    $t = trim(preg_replace('/^```(?:json)?|```$/m', '', trim($text)));
    $a = strpos($t, '{'); $b = strrpos($t, '}');
    if ($a === false || $b === false || $b <= $a) return null;
    $data = json_decode(substr($t, $a, $b - $a + 1), true);
    return is_array($data) ? $data : null;
}
