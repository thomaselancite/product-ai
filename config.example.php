<?php
/*
 * Antoine IA — configuration.
 * Ce fichier contient des secrets : ne le partagez pas et ne le publiez pas.
 */
if (!defined('ANTOINE')) { http_response_code(404); exit; }

return [

    // Clé d'API Claude (console : https://platform.claude.com)
    'anthropic_key' => 'A-REMPLACER',

    // Jeton de la connexion Notion en lecture seule (https://app.notion.com/developers/connections)
    'notion_token'  => 'A-REMPLACER',

    // Mot de passe de la page d'administration (admin.php) : au moins 12 caractères
    'admin_password' => 'A-REMPLACER',

    // Agences : 'Nom de l'agence' => 'code d'accès' (12 caractères minimum, un code différent par agence).
    // Supprimer une ligne retire l'accès immédiatement.
    'agencies' => [
        'Junto' => 'A-REMPLACER-code-agence',
    ],

    // Limites
    'hourly_limit'   => 100,   // appels à Claude par agence et par heure (0 = illimité)
    'monthly_budget' => 50,   // dollars par mois ; l'outil s'arrête au-delà (0 = pas de plafond)

    // Base Notion d'Elan Cité
    'notion_ds'     => '4ec6918b-8d00-4416-bfed-a414dc521cd9', // source de données « Produits »
    'notion_brand'  => 'A-REMPLACER',     // page « Marque & positionnement »
    'notion_home'   => 'A-REMPLACER',     // page d'accueil de la base
    'notion_prompts'=> '',
	'cache_minutes' => 10,                                      // délai avant qu'une modification Notion apparaisse

    // Modèle et tarifs (Claude Sonnet 5.5, tarifs relevés le 9 octobre 2026 sur
    // https://platform.claude.com/docs/en/about-claude/pricing — à mettre à jour s'ils changent)
    'model'            => 'claude-sonnet-5-5',
    'effort'           => 'medium',  // low, medium ou high ; '' = valeur par défaut de l'API
    'max_tokens'       => 16000,
    'price_in'         => 2,         // $ par million de jetons d'entrée
    'price_out'        => 10,        // $ par million de jetons de sortie
    'cache_write_mult' => 1.25,
    'cache_read_mult'  => 0.05,
];
