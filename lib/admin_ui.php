<?php
if (!defined('ANTOINE')) { http_response_code(404); exit; }

/*
 * Coque commune des pages d'administration : sidebar (marque, navigation, retour à l'outil) et titre de page.
 * Style : assets/design.css puis assets/admin.css. Les pages gardent leur propre contenu et leurs propres règles CSS.
 */

/** Liens de <head> communs (feuilles de style et polices). */
function admin_head_links() {
    return '<link rel="preconnect" href="https://fonts.googleapis.com">' . "\n"
        . '<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>' . "\n"
        . '<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">' . "\n"
        . '<link rel="stylesheet" href="assets/design.css?v=1">' . "\n"
        . '<link rel="stylesheet" href="assets/admin.css?v=1">' . "\n";
}

/** Ouvre la coque : sidebar puis <main> avec le titre. $nav = false masque la navigation (avant connexion). */
function admin_shell_open($active, $title, $nav = true) {
    $ico = function ($d) { return '<svg class="nav-ico" viewBox="0 0 24 24" aria-hidden="true">' . $d . '</svg>'; };
    $items = [
        'admin' => ['admin.php', 'Administration', $ico('<circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.7 1.7 0 0 0 .3 1.8l.1.1a2 2 0 1 1-2.8 2.8l-.1-.1a1.7 1.7 0 0 0-1.8-.3 1.7 1.7 0 0 0-1 1.5V21a2 2 0 1 1-4 0v-.1a1.7 1.7 0 0 0-1.1-1.5 1.7 1.7 0 0 0-1.8.3l-.1.1a2 2 0 1 1-2.8-2.8l.1-.1a1.7 1.7 0 0 0 .3-1.8 1.7 1.7 0 0 0-1.5-1H3a2 2 0 1 1 0-4h.1a1.7 1.7 0 0 0 1.5-1.1 1.7 1.7 0 0 0-.3-1.8l-.1-.1a2 2 0 1 1 2.8-2.8l.1.1a1.7 1.7 0 0 0 1.8.3H9a1.7 1.7 0 0 0 1-1.5V3a2 2 0 1 1 4 0v.1a1.7 1.7 0 0 0 1 1.5 1.7 1.7 0 0 0 1.8-.3l.1-.1a2 2 0 1 1 2.8 2.8l-.1.1a1.7 1.7 0 0 0-.3 1.8V9a1.7 1.7 0 0 0 1.5 1H21a2 2 0 1 1 0 4h-.1a1.7 1.7 0 0 0-1.5 1z"/>')],
        'analyse' => ['analyse.php', 'Analyse de la base', $ico('<path d="M4 20V10M10 20V4M16 20v-7M22 20H2"/>')],
        'cartographie' => ['cartographie.php', 'Sujets manquants', $ico('<path d="M9 4 3 6v14l6-2 6 2 6-2V4l-6 2z"/><path d="M9 4v14M15 6v14"/>')],
        'glossaire' => ['glossaire.php', 'Glossaire', $ico('<path d="M4 5h16M4 12h16M4 19h10"/>')],
        'affirmations' => ['affirmations.php', 'Affirmations', $ico('<circle cx="12" cy="12" r="9"/><path d="M8.5 12.5l2.5 2.5 4.5-5"/>')],
    ];
    $h = function ($t) { return htmlspecialchars($t, ENT_QUOTES, 'UTF-8'); };
    echo '<div class="admin-shell">' . "\n" . '<aside class="admin-side">' . "\n";
    echo '<div class="admin-brand"><div class="row"><span class="logo" aria-hidden="true">EC</span><strong>ElanCité Product AI</strong></div><span class="sub">Administration</span></div>' . "\n";
    if ($nav) {
        echo '<nav class="admin-nav" aria-label="Administration">';
        foreach ($items as $k => $it) {
            echo '<a href="' . $it[0] . '"' . ($k === $active ? ' aria-current="page"' : '') . '>' . $it[2] . $h($it[1]) . '</a>';
        }
        echo '</nav>' . "\n";
    }
    echo '<div class="admin-back"><a href="index.php">← Retour à l\'outil</a></div>' . "\n";
    echo '</aside>' . "\n" . '<div class="admin-main">' . "\n" . '<main>' . "\n";
    echo '<div class="page-title"><h1>' . $h($title) . '</h1></div>' . "\n";
}

/** Ferme la coque ouverte par admin_shell_open(). */
function admin_shell_close() {
    echo '</main>' . "\n" . '</div>' . "\n" . '</div>' . "\n";
}
