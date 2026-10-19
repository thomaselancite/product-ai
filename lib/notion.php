<?php
if (!defined('ANTOINE')) { http_response_code(404); exit; }

const ANTOINE_IA_NOTION_VERSION = '2026-03-11';

function antoine_ia_notion_request($method, $path, $body, $token) {
    [$code, $json, $err] = antoine_http($method, (antoine_config()['notion_api'] ?? 'https://api.notion.com') . $path, [
        'Authorization'  => 'Bearer ' . $token,
        'Notion-Version' => ANTOINE_IA_NOTION_VERSION,
        'Content-Type'   => 'application/json',
    ], $body, 30);
    if ($code === 0) throw new AntoineError('antoine_notion', 'Notion ne répond pas : ' . $err, 502);
    if ($code >= 300) {
        $msg = is_array($json) && isset($json['message']) ? $json['message'] : 'erreur ' . $code;
        if ($code === 404) $msg .= " (vérifiez que la page est partagée avec la connexion Notion de l'outil)";
        throw new AntoineError('antoine_notion', 'Lecture de Notion impossible : ' . $msg, 502);
    }
    return $json;
}

function antoine_ia_hex($id) { return strtolower(str_replace('-', '', (string) $id)); }

function antoine_ia_prop_text($prop) {
    if (!is_array($prop) || !isset($prop['type'])) return '';
    $t = $prop['type'];
    if ($t === 'title' || $t === 'rich_text') {
        return trim(implode('', array_map(function ($r) { return $r['plain_text'] ?? ''; }, $prop[$t] ?? [])));
    }
    if ($t === 'select' || $t === 'status') return $prop[$t]['name'] ?? '';
    if ($t === 'multi_select') return array_map(function ($o) { return $o['name']; }, $prop['multi_select'] ?? []);
    return '';
}

/** Statut d'une page hors base Produits : « À valider » tant que son encadré de tête le mentionne. */
function antoine_ia_status_from_encadre($enc) { return stripos((string) $enc, 'valider') !== false ? 'À valider' : 'Validé'; }

/**
 * Sous-pages directes (un seul niveau) de la page d'accueil, dans l'ordre de Notion.
 * Seuls les blocs « child_page » comptent : la base Produits (child_database) et les simples liens sont ignorés,
 * ainsi que les pages dans la corbeille.
 */
function antoine_ia_home_subpages($homeId, $token) {
    $out = [];
    $cursor = null;
    do {
        $q = '?page_size=100' . ($cursor ? '&start_cursor=' . rawurlencode($cursor) : '');
        $r = antoine_ia_notion_request('GET', '/v1/blocks/' . $homeId . '/children' . $q, null, $token);
        foreach ($r['results'] ?? [] as $b) {
            if (($b['type'] ?? '') !== 'child_page' || !empty($b['in_trash']) || !empty($b['archived'])) continue;
            $out[] = ['id' => antoine_ia_hex($b['id']), 'nom' => trim((string) ($b['child_page']['title'] ?? '')) ?: 'Sans titre',
                      'maj' => $b['last_edited_time'] ?? ''];
        }
        $cursor = !empty($r['has_more']) ? ($r['next_cursor'] ?? null) : null;
    } while ($cursor);
    return $out;
}

/**
 * Lit toute la base : pages produits, Marque & positionnement, autres sous-pages directes de l'accueil, accueil.
 * Le résultat est gardé en cache quelques minutes pour limiter les appels à Notion.
 */
function antoine_ia_load_kb($force) {
    $s = antoine_config();
    if (!$force) {
        $cached = antoine_read('kb', null);
        if ($cached && isset($cached['expires']) && $cached['expires'] > time()) return $cached['kb'];
    }
    if (!$s['notion_token'] || strpos($s['notion_token'], 'REMPLACER') !== false) throw new AntoineError('antoine_config', "Le jeton Notion n'est pas configuré.", 500);
    $token = $s['notion_token'];

    // 1. Lignes de la base Produits
    $rows = [];
    $cursor = null;
    do {
        $body = ['page_size' => 100];
        if ($cursor) $body['start_cursor'] = $cursor;
        $r = antoine_ia_notion_request('POST', '/v1/data_sources/' . rawurlencode($s['notion_ds']) . '/query', $body, $token);
        foreach ($r['results'] ?? [] as $page) if (($page['object'] ?? '') === 'page') $rows[] = $page;
        $cursor = !empty($r['has_more']) ? ($r['next_cursor'] ?? null) : null;
    } while ($cursor);

    $names = [ antoine_ia_hex($s['notion_brand']) => 'Marque & positionnement', antoine_ia_hex($s['notion_home']) => "page d'accueil de la base" ];

    // Autres sous-pages directes de l'accueil (ex. Réglementation & ressources externes) : prises en compte sans
    // modification du code. Marque & positionnement garde son traitement ; Consignes Claude est lue plus bas.
    $extras = []; $extrasErr = null;
    if ($s['notion_home']) {
        $skip = array_filter([antoine_ia_hex($s['notion_brand']), antoine_ia_hex($s['notion_prompts'] ?? '')]);
        try {
            foreach (antoine_ia_home_subpages(antoine_ia_hex($s['notion_home']), $token) as $sp) {
                if (in_array($sp['id'], $skip, true) || strcasecmp($sp['nom'], 'Consignes Claude') === 0) continue;
                $extras[] = $sp;
                $names[$sp['id']] = $sp['nom'];
            }
        } catch (AntoineError $e) { $extrasErr = $e->getMessage(); }
    }
    $pages = [];
    foreach ($rows as $row) {
        $pr = $row['properties'] ?? [];
        $nom = antoine_ia_prop_text($pr['Nom'] ?? null) ?: 'Sans nom';
        $names[antoine_ia_hex($row['id'])] = $nom;
        $pages[] = [
            'id'          => antoine_ia_hex($row['id']),
            'type'        => 'produit',
            'nom'         => $nom,
            'statut'      => antoine_ia_prop_text($pr['Statut'] ?? null) ?: 'À valider',
            'famille'     => antoine_ia_prop_text($pr['Famille'] ?? null),
            'marches'     => (array) antoine_ia_prop_text($pr['Marchés'] ?? null),
            'marches_doc' => (array) antoine_ia_prop_text($pr['Marchés documentés'] ?? null),
            'description' => antoine_ia_prop_text($pr['Description courte'] ?? null),
            'maj'         => $row['last_edited_time'] ?? '',
            'notion_url'  => $row['url'] ?? '',
        ];
    }

    // 2. Contenu des pages, en Markdown
    foreach ($pages as &$p) {
        $md = antoine_ia_notion_request('GET', '/v1/pages/' . $p['id'] . '/markdown', null, $token);
        $enc = '';
        $p['contenu'] = antoine_ia_md_convert($md['markdown'] ?? '', $names, $enc);
        $p['encadre'] = $enc;
        if (!empty($md['truncated'])) $p['contenu'] .= "\n\n> Page tronquée lors de la lecture : contenu incomplet.";
    }
    unset($p);

    $brand = null;
    if ($s['notion_brand']) {
        $md = antoine_ia_notion_request('GET', '/v1/pages/' . antoine_ia_hex($s['notion_brand']) . '/markdown', null, $token);
        $enc = '';
        $contenu = antoine_ia_md_convert($md['markdown'] ?? '', $names, $enc);
        // Cette page n'a pas de propriété Statut : elle est « À valider » tant que son encadré le mentionne.
        $brand = ['id' => antoine_ia_hex($s['notion_brand']), 'type' => 'marque', 'nom' => 'Marque & positionnement',
                  'statut' => antoine_ia_status_from_encadre($enc),
                  'famille' => '', 'marches' => [], 'description' => '', 'encadre' => $enc, 'contenu' => $contenu, 'maj' => '', 'notion_url' => ''];
    }

    // Même traitement que Marque & positionnement : statut lu dans l'encadré, titres de sections conservés.
    // Une page illisible est signalée (admin.php) sans bloquer le reste de la base.
    $transverses = [];
    foreach ($extras as $sp) {
        $t = ['id' => $sp['id'], 'type' => 'transverse', 'nom' => $sp['nom'], 'statut' => 'À valider',
              'famille' => '', 'marches' => [], 'description' => '', 'encadre' => '', 'contenu' => '', 'maj' => $sp['maj'], 'notion_url' => ''];
        try {
            $md = antoine_ia_notion_request('GET', '/v1/pages/' . $sp['id'] . '/markdown', null, $token);
            $enc = '';
            $t['contenu'] = antoine_ia_md_convert($md['markdown'] ?? '', $names, $enc);
            $t['encadre'] = $enc;
            $t['statut'] = antoine_ia_status_from_encadre($enc);
            if (!empty($md['truncated'])) $t['contenu'] .= "\n\n> Page tronquée lors de la lecture : contenu incomplet.";
        } catch (AntoineError $e) { $t['erreur'] = $e->getMessage(); }
        $transverses[] = $t;
    }

    $home = null; $homeRaw = '';
    if ($s['notion_home']) {
        try { $md = antoine_ia_notion_request('GET', '/v1/pages/' . antoine_ia_hex($s['notion_home']) . '/markdown', null, $token); } catch (AntoineError $e) { $md = null; }
        if ($md) {
            $homeRaw = (string) ($md['markdown'] ?? '');
            $enc = '';
            $contenu = antoine_ia_md_convert($homeRaw, $names, $enc);
            $contenu = preg_replace('/\n## Contenu\b.*$/s', '', $contenu); // liens vers la base, inutiles ici
            $home = ['presentation' => $enc, 'contenu' => trim($contenu)];
        }
    }

    // Consignes données à Claude : page « Consignes Claude », sous-page de l'accueil (ou identifiant dans config.php)
    $consignes = null;
    $cid = antoine_ia_hex($s['notion_prompts'] ?? '');
    if ($cid === '' && preg_match('/<page\b[^>]*url="[^"]*?([0-9a-f]{32})[^"]*"[^>]*>\s*Consignes Claude\s*<\/page>/i', $homeRaw, $m)) $cid = strtolower($m[1]);
    if ($cid !== '') {
        try {
            $md = antoine_ia_notion_request('GET', '/v1/pages/' . $cid . '/markdown', null, $token);
            $consignes = antoine_ia_parse_consignes(antoine_ia_md_convert($md['markdown'] ?? '', $names, $enc, false));
            $consignes['page'] = $cid;
        } catch (AntoineError $e) { $consignes = ['erreur' => $e->getMessage()]; }
    }

    $kb = ['pages' => $pages, 'brand' => $brand, 'transverses' => $transverses, 'transverses_erreur' => $extrasErr,
           'home' => $home, 'consignes' => $consignes, 'loaded_at' => gmdate('c')];
    antoine_write('kb', ['expires' => time() + max(1, (int) $s['cache_minutes']) * 60, 'kb' => $kb]);
    return $kb;
}

/**
 * Retire les barres obliques d'échappement de Notion (\{marché\}, \>, \$…).
 * $pipe = false garde « \| » : dans les tableaux Markdown, la barre verticale d'une cellule doit rester échappée.
 */
function antoine_ia_unescape($t, $pipe = true) {
    return preg_replace($pipe ? '/\\\\([\\\\*~`$\[\]<>{}|^])/' : '/\\\\([\\\\*~`$\[\]<>{}^])/', '$1', (string) $t);
}

/**
 * Convertit le Markdown enrichi de Notion en Markdown simple :
 * tableaux en lignes « | a | b | », encadrés (callout) en citations, mentions en texte.
 * Le premier encadré est renvoyé à part ($encadre).
 * Les échappements de Notion sont retirés ($unescape), sauf pour Consignes Claude, désespacée par antoine_ia_parse_consignes.
 */
function antoine_ia_md_convert($md, $names, &$encadre, $unescape = true) {
    $md = str_replace("\r", '', (string) $md);
    // Mentions de pages : remplacées par le nom de la page
    $md = preg_replace_callback('/<mention-page\b[^>]*?url="([^"]*)"[^>]*?(?:\/>|>(.*?)<\/mention-page>)/s', function ($m) use ($names) {
        if (preg_match('/([0-9a-f]{32})/i', str_replace('-', '', $m[1]), $id) && isset($names[strtolower($id[1])])) return $names[strtolower($id[1])];
        return isset($m[2]) && trim($m[2]) !== '' ? trim($m[2]) : 'page de la base';
    }, $md);
    $md = preg_replace('/<mention-date\b[^>]*?start="([^"]+)"[^>]*\/>/', '$1', $md);
    $md = preg_replace('/<(page|database)\b[^>]*>(.*?)<\/\1>/s', '$2', $md);
    // Encadrés
    $first = true;
    $md = preg_replace_callback('/<callout\b[^>]*>(.*?)<\/callout>/s', function ($m) use (&$encadre, &$first) {
        $t = trim(preg_replace('/\s*\n\s*/', ' ', $m[1]));
        if ($first) { $encadre = $t; $first = false; return ''; }
        return '> ' . $t;
    }, $md);
    // Tableaux
    $md = preg_replace_callback('/<table\b[^>]*>(.*?)<\/table>/s', function ($m) {
        preg_match_all('/<tr\b[^>]*>(.*?)<\/tr>/s', $m[1], $rows);
        $out = [];
        foreach ($rows[1] as $i => $r) {
            preg_match_all('/<td\b[^>]*>(.*?)<\/td>/s', $r, $cells);
            $c = array_map(function ($x) { return str_replace('|', '\|', trim(preg_replace('/\s+/', ' ', $x))); }, $cells[1]);
            $out[] = '| ' . implode(' | ', $c) . ' |';
            if ($i === 0) $out[] = '|' . str_repeat(' --- |', max(1, count($c)));
        }
        return "\n" . implode("\n", $out) . "\n";
    }, $md);
    // Balises restantes (blocs vides, colonnes…) : on garde le texte
    $md = preg_replace('/<\/?[a-z][a-z0-9-]*\b[^>]*>/i', '', $md);
    $md = preg_replace('/^\t+/m', '', $md);
    $md = preg_replace("/\n{3,}/", "\n\n", $md);
    if ($unescape) { $md = antoine_ia_unescape($md, false); $encadre = antoine_ia_unescape($encadre, false); }
    return trim($md);
}

/** Découpe la page « Consignes Claude » en sections « Relecture », « Brief », « Questions » (titres de niveau 2). */
function antoine_ia_parse_consignes($md) {
    $out = [];
    $parts = preg_split('/^##\s+(.+)$/m', "\n" . $md, -1, PREG_SPLIT_DELIM_CAPTURE);
    for ($i = 1; $i + 1 < count($parts); $i += 2) {
        $title = strtolower(trim(strtr($parts[$i], ['é' => 'e', 'É' => 'e', 'è' => 'e'])));
        $key = ['relecture' => 'relecture', 'brief' => 'brief', 'questions' => 'questions'][$title] ?? null;
        // Notion échappe certains caractères (\{marché\}, \>) : on retire ces barres obliques
        if ($key) $out[$key] = trim(antoine_ia_unescape($parts[$i + 1]));
    }
    return $out;
}

/** Texte de la base envoyé à Claude, et liste des pages utilisées. */
function antoine_ia_kb_text($kb, $includeDraft) {
    $parts = [];
    $b = $kb['brand'];
    $parts[] = $b
        ? "=== PAGE : Marque & positionnement (transverse) | statut : {$b['statut']} ===\n" . ($b['encadre'] ? "Encadré : {$b['encadre']}\n" : '') . $b['contenu']
        : "=== PAGE : Marque & positionnement ===\n(page non disponible)";
    foreach ($kb['transverses'] ?? [] as $t) {
        if (!empty($t['erreur'])) continue;
        $parts[] = "=== PAGE : {$t['nom']} (transverse) | statut : {$t['statut']} ===\n" . ($t['encadre'] ? "Encadré : {$t['encadre']}\n" : '') . $t['contenu'];
    }
    $used = []; $excluded = [];
    foreach ($kb['pages'] as $p) {
        if ($p['statut'] === 'Obsolète') continue;
        if (!$includeDraft && $p['statut'] !== 'Validé') { $excluded[] = $p['nom']; continue; }
        $used[] = ['name' => $p['nom'], 'status' => $p['statut']];
        $parts[] = "=== PAGE PRODUIT : {$p['nom']} | statut : {$p['statut']} | famille : {$p['famille']} | marchés : " . (implode(', ', $p['marches']) ?: 'non renseignés')
            . ' | marchés documentés : ' . (implode(', ', $p['marches_doc'] ?? []) ?: 'non renseignés') . " ===\n"
            . ($p['description'] ? "Description courte : {$p['description']}\n" : '')
            . ($p['encadre'] ? "Encadré : {$p['encadre']}\n" : '')
            . $p['contenu'];
    }
    return ['text' => "BASE DE CONNAISSANCE\n\n" . implode("\n\n", $parts), 'used' => $used, 'excluded' => $excluded, 'used_count' => count($used)];
}
