<?php
if (!defined('ANTOINE')) { http_response_code(404); exit; }

/*
 * Cartographie du « Glossaire de traduction » (page transverse de la base Notion).
 *
 * Partie 1, sans Claude : lecture des tableaux Markdown de la page (déjà convertis par antoine_ia_md_convert) et calcul
 * de la couverture par section, par langue et par source. Elle repose sur les conventions écrites dans la page :
 * titres de niveau 2 par section, tableaux « Terme FR | UK | US | DE | ES | IT | Sources », « — » = aucun terme sourcé,
 * plusieurs termes d'une même case séparés par « ; ».
 * Partie 2 (antoine_ia_glossary_check) : contrôle des termes que Claude juge absents du glossaire.
 */

function antoine_ia_glossary_langs() { return ['UK', 'US', 'DE', 'ES', 'IT']; }

/** Page transverse « Glossaire de traduction » du cache de la base, ou null. */
function antoine_ia_glossary_find($kb) {
    foreach ($kb['transverses'] ?? [] as $t) {
        if (empty($t['erreur']) && stripos(antoine_ia_norm($t['nom']), 'glossaire') !== false) return $t;
    }
    return null;
}

/** Cellules d'une ligne de tableau Markdown « | a | b \| c | » (la barre échappée reste dans la cellule). */
function antoine_ia_glossary_cells($line) {
    $line = trim($line);
    $line = preg_replace('/^\|/', '', $line);
    $line = preg_replace('/(?<!\\\\)\|$/', '', $line);
    return array_map(function ($c) { return trim(str_replace('\|', '|', $c)); }, preg_split('/(?<!\\\\)\|/', $line));
}

/** Colonne d'en-tête → 'FR', 'SRC', une liste de langues ou null (colonne inconnue). */
function antoine_ia_glossary_col($h) {
    $n = antoine_ia_norm($h);
    if ($n === 'terme fr') return 'FR';
    if ($n === 'sources' || $n === 'source') return 'SRC';
    if (in_array(strtoupper($n), antoine_ia_glossary_langs(), true)) return [strtoupper($n)];
    if (preg_match('/^(uk|us|de|es|it)(?: et |, | \/ )(uk|us|de|es|it)$/', $n, $m)) return [strtoupper($m[1]), strtoupper($m[2])];
    return null;
}

/** Contenu d'une case de langue : ['etat' => absent|present|multi, 'termes' => [...], 'avert' => bool]. */
function antoine_ia_glossary_cell($v) {
    $avert = strpos($v, '⚠') !== false;
    $v = trim(str_replace(['⚠️', '⚠'], '', $v));
    if ($v === '' || preg_match('/^[—–-]+$/u', $v)) return ['etat' => 'absent', 'termes' => [], 'avert' => $avert];
    $t = array_values(array_filter(array_map('trim', explode(';', $v)), function ($x) { return $x !== ''; }));
    return ['etat' => count($t) > 1 ? 'multi' : 'present', 'termes' => $t, 'avert' => $avert];
}

/** Pays d'une liste « - **France** : … » → code de langue ou null. */
function antoine_ia_glossary_country($l) {
    $n = antoine_ia_norm($l);
    foreach (['france' => 'FR', 'royaume-uni' => 'UK', 'royaume uni' => 'UK', 'allemagne' => 'DE', 'espagne' => 'ES', 'italie' => 'IT', 'états-unis' => 'US', 'etats-unis' => 'US'] as $k => $c) if ($n === $k) return $c;
    return null;
}

/**
 * Lit le glossaire. Renvoie ['sections' => [...], 'codes' => [code => description], 'erreurs' => [...]].
 * Section de type « table » : 'cols' (langue => true si la colonne existe), 'lignes' (fr, cases par langue, sources) ;
 * de type « liste » : 'items' (langue => nombre d'éléments, 0 = aucun).
 */
function antoine_ia_glossary_parse($md) {
    $lines = explode("\n", str_replace("\r", '', (string) $md));
    $sections = []; $codes = []; $erreurs = [];
    $cur = null; $i = 0; $n = count($lines);
    $push = function () use (&$cur, &$sections) { if ($cur !== null && ($cur['lignes'] || $cur['items'] || $cur['type'] !== null)) $sections[] = $cur; };
    $new = function ($titre) { return ['titre' => $titre, 'type' => null, 'cols' => [], 'lignes' => [], 'items' => [], 'texte' => []]; };
    $inCodes = false;
    while ($i < $n) {
        $l = $lines[$i];
        if (preg_match('/^##\s+(.+?)\s*$/', $l, $m)) {
            $push(); $cur = $new(trim($m[1])); $inCodes = stripos(antoine_ia_norm($m[1]), 'codes de sources') === 0;
            $i++; continue;
        }
        if ($cur === null) { $i++; continue; }
        if ($inCodes && preg_match('/^[-*]\s+\*\*(.+?)\*\*\s*:\s*(.+)$/u', $l, $m)) {
            foreach (preg_split('/\s*,\s*/', $m[1]) as $c) $codes[trim($c)] = trim($m[2]);
            $i++; continue;
        }
        if (strncmp(ltrim($l), '|', 1) === 0) {
            $block = [];
            while ($i < $n && strncmp(ltrim($lines[$i]), '|', 1) === 0) { $block[] = $lines[$i]; $i++; }
            if (count($block) < 2) continue;
            $head = antoine_ia_glossary_cells($block[0]);
            $map = array_map('antoine_ia_glossary_col', $head);
            if (!in_array('FR', $map, true)) { $erreurs[] = "Section « {$cur['titre']} » : tableau sans colonne « Terme FR », ignoré."; continue; }
            foreach ($head as $k => $h) if ($map[$k] === null) $erreurs[] = "Section « {$cur['titre']} » : colonne « $h » non reconnue, ignorée.";
            $cur['type'] = 'table';
            $cols = [];
            foreach ($map as $c) if (is_array($c)) foreach ($c as $lg) $cols[$lg] = true;
            foreach (antoine_ia_glossary_langs() as $lg) $cur['cols'][$lg] = isset($cols[$lg]);
            $cur['partage'] = [];
            foreach ($map as $c) if (is_array($c) && count($c) > 1) $cur['partage'][] = $c;
            foreach (array_slice($block, 1) as $ri => $row) {
                $cells = antoine_ia_glossary_cells($row);
                if (preg_match('/^[\s:-]*$/', str_replace(['|', ' '], '', $row)) || preg_match('/^\|\s*:?-{2,}/', trim($row))) continue; // ligne « --- »
                if (count($cells) !== count($head)) { $erreurs[] = "Section « {$cur['titre']} », ligne " . ($ri + 1) . ' : ' . count($cells) . ' cellule(s) au lieu de ' . count($head) . ', ignorée.'; continue; }
                $line = ['fr' => '', 'fr_absent' => false, 'cases' => [], 'sources' => '', 'codes' => []];
                foreach ($map as $k => $c) {
                    $v = $cells[$k];
                    if ($c === 'FR') { $cell = antoine_ia_glossary_cell($v); $line['fr'] = $cell['etat'] === 'absent' ? '' : implode(' ; ', $cell['termes']); $line['fr_absent'] = $cell['etat'] === 'absent'; }
                    elseif ($c === 'SRC') { $line['sources'] = $v; }
                    elseif (is_array($c)) { $cell = antoine_ia_glossary_cell($v); foreach ($c as $lg) $line['cases'][$lg] = $cell; }
                }
                foreach (array_keys($codes) as $code) if (preg_match('/(?<![A-Za-z0-9-])' . preg_quote($code, '/') . '(?![A-Za-z0-9-])/', $line['sources'])) $line['codes'][] = $code;
                $cur['lignes'][] = $line;
            }
            continue;
        }
        if ($cur['type'] !== 'table' && preg_match('/^[-*]\s+\*\*(.+?)\*\*\s*:\s*(.*)$/u', $l, $m) && !$inCodes) {
            $lg = antoine_ia_glossary_country($m[1]);
            if ($lg !== null) {
                $cur['type'] = 'liste';
                $v = trim($m[2]);
                if (stripos($v, 'aucune source') !== false) $nb = 0;
                elseif (preg_match_all('/«[^»]*»/u', $v, $q) && count($q[0]) > 0) $nb = count($q[0]);
                else $nb = count(array_filter(array_map('trim', explode(',', preg_replace('/\([^)]*\)/u', '', $v)))));
                $cur['items'][$lg] = ($cur['items'][$lg] ?? 0) + $nb;
            }
        }
        $i++;
    }
    $push();
    $sections = array_values(array_filter($sections, function ($s) { return $s['type'] !== null; }));
    return ['sections' => $sections, 'codes' => $codes, 'erreurs' => $erreurs];
}

/**
 * Agrégats pour l'affichage : couverture par section et par langue, grille des termes, langues × sources.
 * Une case « —» ou absente = lacune ; « na » = langue sans colonne dans la section (non traitée).
 */
function antoine_ia_glossary_stats($g) {
    $langs = antoine_ia_glossary_langs();
    $sec = []; $src = []; $totalLignes = 0; $totalLacunes = 0; $totalMulti = 0;
    foreach ($langs as $lg) $src[$lg] = [];
    foreach ($g['sections'] as $s) {
        $row = ['titre' => $s['titre'], 'type' => $s['type'], 'lignes' => count($s['lignes']), 'langues' => []];
        foreach ($langs as $lg) {
            if ($s['type'] === 'liste') { $row['langues'][$lg] = isset($s['items'][$lg]) ? ['nb' => $s['items'][$lg]] : ['na' => true]; continue; }
            if (empty($s['cols'][$lg])) { $row['langues'][$lg] = ['na' => true]; continue; }
            $p = 0; $m = 0; $a = 0;
            foreach ($s['lignes'] as $l) {
                $e = $l['cases'][$lg]['etat'] ?? 'absent';
                if ($e === 'absent') $a++; elseif ($e === 'multi') $m++; else $p++;
                if ($e !== 'absent') {
                    $k = $l['codes'] ?: ['(autres)'];
                    foreach ($k as $code) $src[$lg][$code] = ($src[$lg][$code] ?? 0) + 1;
                }
            }
            $tot = max(1, $p + $m + $a);
            $row['langues'][$lg] = ['present' => $p, 'multi' => $m, 'absent' => $a, 'taux' => round(100 * ($p + $m) / $tot)];
            $totalLignes += $p + $m + $a; $totalLacunes += $a; $totalMulti += $m;
        }
        $sec[] = $row;
    }
    $codes = [];
    foreach ($src as $lg => $l) foreach ($l as $c => $v) $codes[$c] = true;
    $order = array_keys($g['codes']); $order[] = '(autres)';
    $codes = array_values(array_filter($order, function ($c) use ($codes) { return isset($codes[$c]); }));
    return ['sections' => $sec, 'sources' => $src, 'codes_utilises' => $codes, 'cases' => $totalLignes, 'lacunes' => $totalLacunes, 'multi' => $totalMulti];
}

/**
 * Contrôle sans appel à Claude des termes jugés absents du glossaire : la référence doit exister, l'extrait doit se
 * trouver dans la page citée, la langue doit être connue ; un terme déjà présent dans le glossaire est écarté.
 * Renvoie [résultat, nombre de termes retenus, nombre d'avertissements].
 */
function antoine_ia_glossary_check($data, $kb) {
    $docs = antoine_ia_analysis_docs($kb);
    $norm = [];
    foreach ($docs as $name => $text) $norm[antoine_ia_norm($name)] = antoine_ia_norm($text);
    $glo = antoine_ia_glossary_find($kb);
    $gnorm = $glo ? antoine_ia_norm($glo['nom']) : '';
    $gtext = $gnorm !== '' && isset($norm[$gnorm]) ? $norm[$gnorm] : '';
    $titres = [];
    if ($glo) { $p = antoine_ia_glossary_parse($glo['contenu'] ?? ''); foreach ($p['sections'] as $s) $titres[antoine_ia_norm($s['titre'])] = $s['titre']; }
    $all = implode(' ', array_diff_key($norm, [$gnorm => 1]));
    $langs = array_merge(['FR'], antoine_ia_glossary_langs());
    $arr = function ($v) { return is_array($v) ? array_values(array_filter($v, 'is_array')) : []; };

    $out = []; $ecartes = 0; $warn = 0; $seen = [];
    foreach ($arr($data['termes'] ?? null) as $t) {
        $terme = trim(mb_substr(strip_tags((string) ($t['terme'] ?? '')), 0, 120));
        if ($terme === '') continue;
        $w = [];
        $lg = strtoupper(trim((string) ($t['langue'] ?? '')));
        if ($lg === 'GB' || $lg === 'EN') $lg = 'UK';
        if (!in_array($lg, $langs, true)) { $w[] = 'langue « ' . trim((string) ($t['langue'] ?? '')) . ' » inconnue'; $lg = '?'; }
        $nt = antoine_ia_norm($terme);
        if ($gtext !== '' && strpos($gtext, $nt) !== false) { $ecartes++; continue; }  // déjà dans le glossaire
        $key = $lg . '|' . $nt;
        if (isset($seen[$key])) { $ecartes++; continue; }
        $seen[$key] = true;
        $ref = trim((string) ($t['reference'] ?? ''));
        $refOk = antoine_ia_check_ref($ref, $norm);
        $page = antoine_ia_ref_page($ref, $norm);
        if ($page !== null && $page === $gnorm) { $ecartes++; continue; }             // le glossaire n'est pas un usage du terme
        if ($refOk === false) $w[] = 'référence introuvable';
        $ex = antoine_ia_norm($t['extrait'] ?? '');
        $exOk = $ex === '' ? false : (strpos($page !== null ? $norm[$page] : $all, $ex) !== false);
        if (!$exOk) $w[] = 'extrait introuvable';
        elseif (strpos($ex, $nt) === false) $w[] = 'terme absent de l\'extrait';
        $sg = trim((string) ($t['section_glossaire'] ?? ''));
        $sgOk = isset($titres[antoine_ia_norm($sg)]);
        $out[] = ['terme' => $terme, 'langue' => $lg, 'section_glossaire' => $sg !== '' ? $sg : 'Nouvelle section', 'section_existante' => $sgOk,
                  'extrait' => trim((string) ($t['extrait'] ?? '')), 'reference' => $ref, 'pourquoi' => trim((string) ($t['pourquoi'] ?? '')), '_avert' => $w];
        if ($w) $warn++;
        if (count($out) >= 60) break;
    }
    return [['synthese' => (string) ($data['synthese'] ?? ''), 'termes' => $out, 'ecartes' => $ecartes], count($out), $warn];
}
