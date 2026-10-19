<?php
if (!defined('ANTOINE')) { http_response_code(404); exit; }

/*
 * Consignes données à Claude.
 * Elles sont lues dans la page Notion « Consignes Claude » (sections « Relecture », « Brief », « Questions »).
 * Si la page ou une section est introuvable, l'outil utilise les consignes par défaut du dossier lib/consignes/.
 * Le format de réponse (JSON) reste toujours dans ce fichier : l'outil en dépend pour afficher les résultats.
 * La page n'envoie que la tâche et ses paramètres : l'outil ne peut pas servir à interroger Claude sur autre chose.
 */

const ANTOINE_IA_MARKETS = ['France', 'Royaume-Uni', 'Allemagne', 'États-Unis', 'Espagne', 'Italie', 'Distributeurs (reste du monde)', 'Tous marchés'];
const ANTOINE_IA_TARGETS = ['Non précisée', 'Élus', 'Services techniques', 'Police municipale', 'Entreprises privées', 'Grand public'];
const ANTOINE_IA_TYPES   = ['Page produit', 'Article de blog', 'Landing page', 'Post réseaux sociaux', 'Newsletter', 'Communiqué de presse', 'Brochure / fiche commerciale', 'Autre'];

function antoine_ia_pick($v, $allowed, $default) { $v = (string) $v; return in_array($v, $allowed, true) ? $v : $default; }
function antoine_ia_str($v, $max) { return mb_substr(trim(strip_tags((string) $v)), 0, $max); }

function antoine_ia_ctx($c, $kb) {
    $products = array_map(function ($p) { return $p['name']; }, $kb['used']);
    return [
        'product' => in_array((string) ($c['product'] ?? ''), $products, true) ? (string) $c['product'] : '',
        'market'  => antoine_ia_pick($c['market'] ?? '', ANTOINE_IA_MARKETS, 'France'),
        'target'  => antoine_ia_pick($c['target'] ?? '', ANTOINE_IA_TARGETS, 'Non précisée'),
        'type'    => antoine_ia_pick($c['type'] ?? '', ANTOINE_IA_TYPES, 'Autre'),
        'lang'    => (($c['lang'] ?? 'fr') === 'en') ? 'en' : 'fr',
        'name'    => antoine_ia_str($c['name'] ?? '', 200),
        'idea'    => antoine_ia_str($c['idea'] ?? '', 4000),
    ];
}

/** Consignes par défaut (fichiers lib/consignes/*.md, identiques à la page Notion lors de sa création). */
function antoine_ia_default_consignes() {
    $d = __DIR__ . '/consignes/';
    return ['relecture' => (string) @file_get_contents($d . 'relecture.md'),
            'brief'     => (string) @file_get_contents($d . 'brief.md'),
            'questions' => (string) @file_get_contents($d . 'questions.md')];
}

/** Consigne d'une tâche : section Notion si elle existe, sinon texte par défaut. */
function antoine_ia_rules($consignes, $key) {
    $t = is_array($consignes) ? trim((string) ($consignes[$key] ?? '')) : '';
    if ($t !== '') return $t;
    return trim(antoine_ia_default_consignes()[$key]);
}

function antoine_ia_build_request($task, $p, $kb, $consignes = null) {
    $ctx = antoine_ia_ctx(is_array($p['ctx'] ?? null) ? $p['ctx'] : [], $kb);

    if ($task === 'review') {
        $doc = trim((string) ($p['doc'] ?? ''));
        if (mb_strlen($doc) < 20) throw new AntoineError('antoine_input', 'Le contenu à relire est vide.', 400);
        if (mb_strlen($doc) > 80000) throw new AntoineError('antoine_input', 'Le contenu dépasse 80 000 caractères. Découpez-le en parties plus courtes.', 400);
        $user = "CONTEXTE DE LA RELECTURE\n"
            . '- Produit principal : ' . ($ctx['product'] ?: 'plusieurs ou non précisé') . "\n"
            . "- Marché : {$ctx['market']}\n- Cible : {$ctx['target']}\n- Type de contenu : {$ctx['type']}\n"
            . '- Langue des champs "commentaire", "pourquoi", "resume", "points_forts", "points_bloquants" : ' . ($ctx['lang'] === 'en' ? 'anglais' : 'français') . "\n"
            . "- Langue des champs \"proposition\" et \"element\" : celle du contenu relu\n\n"
            . 'CONTENU À RELIRE' . ($ctx['name'] ? ' (fichier : ' . $ctx['name'] . ')' : '') . "\n" . $doc;
        return ['instructions' => antoine_ia_rules($consignes, 'relecture') . "\n\n" . antoine_ia_review_format(), 'messages' => [['role' => 'user', 'content' => $user]], 'json' => true];
    }

    if ($task === 'brief') {
        if (mb_strlen($ctx['idea']) < 10) throw new AntoineError('antoine_input', "Décrivez l'idée de contenu.", 400);
        $user = "DEMANDE\n- Idée de contenu : {$ctx['idea']}\n- Produit principal : " . ($ctx['product'] ?: 'plusieurs ou non précisé')
            . "\n- Marché : {$ctx['market']}\n- Cible : {$ctx['target']}\n- Type de contenu : {$ctx['type']}";
        return ['instructions' => antoine_ia_rules($consignes, 'brief') . "\n\n" . antoine_ia_brief_format(), 'messages' => [['role' => 'user', 'content' => $user]], 'json' => true];
    }

    if ($task === 'ask') {
        $turns = is_array($p['turns'] ?? null) ? array_slice($p['turns'], -20) : [];
        $messages = [];
        foreach ($turns as $t) {
            $role = (($t['role'] ?? '') === 'assistant') ? 'assistant' : 'user';
            $content = mb_substr((string) ($t['content'] ?? ''), 0, 8000);
            if ($content === '') continue;
            if ($messages && end($messages)['role'] === $role) continue; // alternance obligatoire
            $messages[] = ['role' => $role, 'content' => $content];
        }
        while ($messages && $messages[0]['role'] !== 'user') array_shift($messages);
        if (!$messages || end($messages)['role'] !== 'user') throw new AntoineError('antoine_input', 'Posez une question.', 400);
        // Claude déduit le marché de la question : market et usage éventuellement reçus sont ignorés.
        // Par sécurité, une ancienne balise restée dans la consigne n'est jamais transmise brute.
        $rules = str_replace(['{marché}', '{marche}', '{usage}'], 'à déduire de la question', antoine_ia_rules($consignes, 'questions'));
        return ['instructions' => $rules . "\n\n" . antoine_ia_ask_format(), 'messages' => $messages, 'json' => false];
    }

    throw new AntoineError('antoine_input', 'Tâche inconnue.', 400);
}

/* ---------- Formats de réponse (toujours dans le code) ---------- */

function antoine_ia_review_format() {
    return <<<'TXT'
FORMAT DE RÉPONSE
Valeurs possibles de "categorie" : fonctionnalite_inventee, donnee_inexacte, affirmation_non_sourcee, generalite, reglementation, terminologie_marche, confusion_produit, ton_marque, formulation_a_eviter, point_a_verifier.
Valeurs possibles de "gravite" : bloquant, a_corriger, a_verifier. Valeurs possibles de "verdict" : publiable, corrections_mineures, a_retravailler.
Réponds uniquement avec un objet JSON de cette forme, sans texte avant ni après :
{"commentaires":[{"citation":"","categorie":"","gravite":"","commentaire":"","proposition":"","reference":""}],
 "complements":[{"element":"","pourquoi":"","ou":"","reference":""}],
 "synthese":{"verdict":"","resume":"","points_forts":[""],"points_bloquants":[""]}}
TXT;
}

/** Questions : l'interface affiche les sources en pastilles, les points non tranchés et à compléter en encarts, et les relances en boutons. */
function antoine_ia_ask_format() {
    return <<<'TXT'
FORMAT DE RÉPONSE
L'outil met en forme ta réponse à partir de ce format :
- Paragraphes courts et listes à puces « - ». Pas de titre ni de tableau.
- Pour une liste d'informations, une information par puce : « - **Libellé** : texte (Page > Section) ».
- Chaque source entre parenthèses, à la fin de la phrase ou de la puce qu'elle appuie : « (Page > Section) » ou « (Page > Section > Sous-section) », avec les noms exacts des pages et des sections de la base. Plusieurs sources dans les mêmes parenthèses sont séparées par « ; ».
- Une information d'une section « Points d'attention à lever » : paragraphe à part qui commence par « **Point non tranché** : ».
- Une information d'une section « À compléter » : paragraphe à part qui commence par « **À compléter** : ».
- Le signalement des pages au statut « À valider » : une ligne à part, en fin de réponse, qui commence par « Statut : ».
- Dernière ligne, toujours : « Relances : » suivi de deux ou trois questions courtes que l'utilisateur pourrait poser ensuite et auxquelles la base permet de répondre, séparées par « | ».
TXT;
}

function antoine_ia_brief_format() {
    return <<<'TXT'
FORMAT DE RÉPONSE
Réponds uniquement avec un objet JSON, sans texte avant ni après :
{"titre_de_travail":"","objectif":"","cible":{"profil":"","attentes":[""]},"angle":"","messages_cles":[{"message":"","source":""}],"preuves":[{"preuve":"","source":""}],"contraintes_reglementaires":[""],"elements_de_langage":[""],"a_eviter":[""],"structure_suggeree":[""],"appel_a_l_action":"","points_a_verifier":[""],"avertissements":[""]}
TXT;
}
