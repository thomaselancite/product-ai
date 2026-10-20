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

    // Comptes : au premier lancement, l'outil crée les comptes dans data/accounts.php à partir de ces deux réglages,
    // puis ne les relit plus (les comptes se gèrent ensuite depuis l'administration).
    // — compte « admin » : identifiant « admin », mot de passe ci-dessous (12 caractères minimum).
    //   Il sert aussi de secours : tant qu'aucun administrateur actif n'existe, ce mot de passe ouvre le compte « admin ».
    'admin_password' => 'A-REMPLACER',

    // — comptes agences : 'Nom de l'agence' => 'mot de passe initial' (12 caractères minimum).
    //   L'identifiant est le nom en minuscules, sans accents ni espaces (« Junto » => « junto »).
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
