<?php
if (!defined('ANTOINE')) { http_response_code(404); exit; }

/*
 * Affirmations déduites de la base, à confirmer par le PO (page affirmations.php).
 *
 * Chaque affirmation est liée à un extrait copié mot pour mot d'une page de la base. Les verdicts du PO sont conservés
 * dans data/affirmations.php (serveur seulement). À chaque affichage, l'extrait est recherché dans la copie de la base :
 * introuvable = « à revoir » (le verdict est gardé). Aucun appel à Claude pour cette détection.
 */

function antoine_claims_verdicts() { return ['confirmee' => 'Confirmée', 'infirmee' => 'Infirmée', 'a_preciser' => 'À préciser']; }

/** Pages de la base proposées à la génération : nom => texte brut (comme transmis à Claude). */
function antoine_claims_pages($kb) { return antoine_ia_analysis_docs($kb); }

/** Textes normalisés des pages : nom normalisé => texte normalisé. */
function antoine_claims_normdocs($kb) {
    $n = [];
    foreach (antoine_claims_pages($kb) as $name => $text) $n[antoine_ia_norm($name)] = antoine_ia_norm($text);
    return $n;
}

/** Identifiant stable : page + extrait normalisé. */
function antoine_claim_id($page, $extrait) { return substr(sha1(antoine_ia_norm($page) . '|' . antoine_ia_norm($extrait)), 0, 12); }

function antoine_claims_format() {
    return <<<'TXT'
FORMAT DE RÉPONSE
Réponds uniquement avec un objet JSON de cette forme, sans texte avant ni après ("affirmations" est vide si la page n'en contient aucune de nouvelle) :
{"synthese":"",
 "affirmations":[{"affirmation":"","extrait":"","reference":""}]}
TXT;
}

/** $existing : textes des affirmations déjà connues pour la page (non archivées), à ne pas reproposer. */
function antoine_ia_build_claims($consignes, $page, array $existing) {
    $user = "PAGE CIBLE : $page\n\nDéduis de la page cible, dans la BASE DE CONNAISSANCE fournie, des affirmations que le responsable produit peut confirmer ou infirmer, au format demandé.";
    if ($existing) {
        $user .= "\n\nAFFIRMATIONS DÉJÀ CONNUES POUR CETTE PAGE (ne les repropose pas, même reformulées) :\n- " . implode("\n- ", array_map(function ($t) { return mb_substr($t, 0, 200); }, array_slice($existing, 0, 80)));
    }
    return ['instructions' => antoine_ia_rules($consignes, 'affirmations') . "\n\n" . antoine_claims_format(),
            'messages' => [['role' => 'user', 'content' => $user]]];
}

/**
 * Contrôle des affirmations proposées par Claude pour la page $page : la référence doit viser cette page, l'extrait doit s'y
 * trouver mot pour mot (à la mise en forme près), l'affirmation ne doit être ni vide ni déjà connue. Renvoie
 * [nouveaux éléments, ['ecart' => [motif => nombre], 'synthese' => …]].
 */
function antoine_claims_check($data, $kb, $page, array $known) {
    $norm = antoine_claims_normdocs($kb);
    $pn = antoine_ia_norm($page);
    $ptext = $norm[$pn] ?? '';
    $seenId = []; $seenText = [];
    foreach ($known as $k) { $seenId[$k['id']] = true; if (empty($k['archived'])) $seenText[antoine_ia_norm($k['affirmation'])] = true; } // une affirmation archivée peut revenir avec un nouvel extrait
    $arr = is_array($data['affirmations'] ?? null) ? array_values(array_filter($data['affirmations'], 'is_array')) : [];
    $out = []; $ecart = [];
    $drop = function ($m) use (&$ecart) { $ecart[$m] = ($ecart[$m] ?? 0) + 1; };
    foreach ($arr as $a) {
        if (count($out) >= 25) { $drop('au-delà de 25'); continue; }
        $aff = trim(mb_substr(strip_tags((string) ($a['affirmation'] ?? '')), 0, 400));
        $ex = trim(mb_substr((string) ($a['extrait'] ?? ''), 0, 600));
        $ref = trim((string) ($a['reference'] ?? ''));
        if ($aff === '' || $ex === '') { $drop('affirmation ou extrait vide'); continue; }
        if (antoine_ia_ref_page($ref, $norm) !== $pn && $ref !== '') { $drop('référence hors de la page choisie'); continue; }
        if (strpos($ptext, antoine_ia_norm($ex)) === false) { $drop('extrait introuvable dans la page'); continue; }
        $id = antoine_claim_id($page, $ex);
        if (isset($seenId[$id]) || isset($seenText[antoine_ia_norm($aff)])) { $drop('déjà connue'); continue; }
        $seenId[$id] = true; $seenText[antoine_ia_norm($aff)] = true;
        $out[] = ['id' => $id, 'page' => $page, 'reference' => $ref !== '' ? $ref : $page, 'affirmation' => $aff, 'extrait' => $ex,
                  'created_at' => gmdate('c'), 'verdict' => '', 'commentaire' => '', 'verdict_at' => '', 'extrait_verdict' => '', 'archived' => false];
    }
    return [$out, ['ecart' => $ecart, 'synthese' => trim((string) ($data['synthese'] ?? ''))]];
}

/** Ajoute les nouvelles affirmations au fichier (sans toucher aux verdicts existants). Renvoie le nombre ajouté. */
function antoine_claims_add(array $items) {
    $n = 0;
    antoine_update('affirmations', ['items' => []], function ($d) use ($items, &$n) {
        if (!isset($d['items']) || !is_array($d['items'])) $d['items'] = [];
        foreach ($items as $it) if (!isset($d['items'][$it['id']])) { $d['items'][$it['id']] = $it; $n++; }
        return $d;
    });
    return $n;
}

/** Pose, efface, archive ou restaure. $op : verdict | archive | restore. Renvoie [ok, message]. */
function antoine_claims_set($id, $op, $verdict = '', $comment = '') {
    $res = [false, 'Affirmation introuvable.'];
    antoine_update('affirmations', ['items' => []], function ($d) use ($id, $op, $verdict, $comment, &$res) {
        if (!isset($d['items'][$id])) return $d;
        $it = &$d['items'][$id];
        if ($op === 'verdict') {
            if ($verdict !== '' && !isset(antoine_claims_verdicts()[$verdict])) { $res = [false, 'Verdict inconnu.']; return $d; }
            $it['verdict'] = $verdict;
            $it['commentaire'] = mb_substr(trim(strip_tags($comment)), 0, 1000);
            $it['verdict_at'] = $verdict !== '' ? gmdate('c') : '';
            $it['extrait_verdict'] = $verdict !== '' ? $it['extrait'] : '';
            $res = [true, 'Enregistré.'];
        } elseif ($op === 'archive') { $it['archived'] = true; $it['archived_at'] = gmdate('c'); $res = [true, 'Archivée.']; }
        elseif ($op === 'restore') { $it['archived'] = false; $res = [true, 'Restaurée.']; }
        else $res = [false, 'Action inconnue.'];
        return $d;
    });
    return $res;
}

/**
 * État d'une affirmation d'après la copie de la base : 'ok' (extrait toujours présent), 'revoir' (extrait modifié ou
 * supprimé), 'page_absente' (page supprimée, obsolète ou renommée).
 */
function antoine_claim_state($it, array $normDocs) {
    $pn = antoine_ia_norm($it['page']);
    if (!isset($normDocs[$pn])) return 'page_absente';
    return strpos($normDocs[$pn], antoine_ia_norm($it['extrait'])) !== false ? 'ok' : 'revoir';
}

/** Texte actuel de la section citée par la référence (« Page > Section … »), limité à 900 caractères, ou null. */
function antoine_claim_current_section($it, array $rawDocs) {
    $text = $rawDocs[$it['page']] ?? null;
    if ($text === null) return null;
    $parts = array_values(array_filter(array_map('trim', preg_split('/\s*>\s*/u', $it['reference']))));
    array_shift($parts);
    if (!$parts) return null;
    $sec = antoine_ia_norm(end($parts));
    $lines = explode("\n", $text);
    foreach ($lines as $i => $l) {
        if (preg_match('/^#{1,6}\s+(.+)$/', $l, $m) && antoine_ia_norm($m[1]) === $sec) {
            $buf = [];
            for ($j = $i + 1; $j < count($lines) && !preg_match('/^#{1,6}\s/', $lines[$j]); $j++) $buf[] = $lines[$j];
            $t = trim(implode("\n", $buf));
            return $t === '' ? null : mb_substr($t, 0, 900) . (mb_strlen($t) > 900 ? '…' : '');
        }
    }
    return null;
}
