# ElanCité Product AI — site autonome (PHP, sans base de données)

Outil de relecture, de briefs et de questions sur le discours produit et marque d'Elan Cité / Elan City.
La base de connaissance est lue en direct dans Notion ; les réponses viennent de l'API Claude, facturée au compte Anthropic d'Elan Cité.
Les agences entrent avec un code personnel, sans compte Claude ni compte Notion.

## Contenu du dossier

| Fichier | Rôle |
| --- | --- |
| `index.php` | La page de l'outil (ce que voient les agences) |
| `api.php` | Les échanges entre la page, Notion et Claude |
| `admin.php` | Votre page d'administration : consommation, relecture de la base |
| `config.php` | **Vos réglages et secrets** : clés, agences, budget |
| `lib/` | Le code et les consignes par défaut (non accessible depuis le web) |
| `data/` | Cache de la base et compteurs (créés automatiquement, non accessibles depuis le web) |

## 1. Prérequis

- Un hébergement PHP 7.4 ou plus (avec l'extension cURL, présente chez la plupart des hébergeurs).
- Une requête doit pouvoir durer **au moins 2 minutes**, idéalement 5 : une relecture longue prend une à deux minutes. À vérifier auprès de l'hébergeur (`max_execution_time`, délai du serveur).
- Le dossier `data/` doit être accessible en écriture par PHP.

## 2. Clé d'API Claude

1. Sur https://platform.claude.com, avec le compte Elan Cité : configurer la facturation, puis créer une clé d'API.
2. Si la console le permet, y fixer aussi un plafond de dépenses (en plus du budget mensuel de l'outil).

## 3. Connexion Notion en lecture seule

Il faut être propriétaire de l'espace Notion (Workspace Owner).

1. Ouvrir https://app.notion.com/developers/connections → **Internal connections** → **Create a new connection** (nom : ElanCité Product AI).
2. Onglet **Configuration** : ne garder que la **lecture du contenu**, puis copier le jeton.
3. Onglet **Content access** : donner accès à la page d'accueil de la base de connaissance (titre actuel ou ancien « Antoine IA — Base de connaissance produit & marque » : l'outil la repère par son identifiant, pas par son titre). Les pages enfants héritent de l'accès.

## 4. Configuration

Ouvrir `config.php` et remplacer les valeurs marquées `A-REMPLACER` :
- `anthropic_key` : la clé d'API Claude ;
- `notion_token` : le jeton Notion ;
- `admin_password` : un mot de passe d'au moins 12 caractères pour `admin.php` ;
- `agencies` : une ligne par agence, par exemple `'Agence Dupont' => 'dupont-7Kq2-mXr9-2026',` (code de 12 caractères minimum, différent pour chaque agence).

Réglages facultatifs : limite d'appels par agence et par heure (30), budget mensuel en dollars (50), délai de cache de la base (10 minutes), niveau de réflexion du modèle.

## 5. Mise en ligne

1. Déposer le dossier `antoine-ia` sur l'hébergement (FTP), par exemple dans un sous-domaine `outils.elancite.fr` ou un sous-dossier.
2. Ouvrir `admin.php`, se connecter, cliquer sur **Relire la base Notion maintenant** : le message doit indiquer le nombre de pages lues.
3. Ouvrir l'adresse du dossier (`index.php`) et entrer un code d'agence pour tester.
4. Vérifier que `config.php`, `lib/core.php` et `data/usage.php` affichent une page blanche ou une erreur 403/404 dans le navigateur (protection en place).

Le site doit être servi en HTTPS : les codes d'accès circulent à chaque requête.

## 6. Utilisation

- **Mettre à jour la base** : dans Notion. Les modifications apparaissent après le délai de cache, ou immédiatement avec le bouton d'`admin.php`.
- **Statut des pages produits** : propriété « Statut » de la base Produits.
- **Page Marque & positionnement** : elle est considérée « À valider » tant que son encadré de tête contient le mot « valider ». Pour la valider, retirer cette mention.
- **Autres sous-pages de la page d'accueil** (ex. « Réglementation & ressources externes ») : chaque sous-page directe est lue automatiquement et transmise à Claude, sans modification du code, avec le même traitement que Marque & positionnement (statut lu dans l'encadré de tête). Seul le premier niveau est lu : les sous-pages de ces sous-pages ne le sont pas. Les pages dans la corbeille sont ignorées. Une page posée à l'intérieur d'un bloc (colonnes, bloc dépliable) n'est pas une sous-page directe et n'est pas lue.
- **Consignes données à Claude** : elles sont lues dans la page Notion « Consignes Claude », sous-page de la page d'accueil de la base (sections « Relecture », « Brief », « Questions », à ne pas renommer). Si la page ou une section est introuvable, l'outil utilise les consignes par défaut de `lib/consignes/`. `admin.php` indique lesquelles sont utilisées. Le format de réponse reste dans le code.
- **Onglet Questions** : il n'a ni choix de marché ni choix d'usage ; Claude déduit le marché de la question, selon la section « Questions » de Consignes Claude. Si une ancienne balise `{marché}` ou `{usage}` y restait, l'outil la remplace par « à déduire de la question ».
- **Affichage des réponses aux questions** : la réponse s'affiche au fil de l'eau (streaming). Les sources deviennent des pastilles qui ouvrent la page et la section dans « Consulter la base » ; les points non tranchés et à compléter s'affichent en encarts ; des questions de relance sont proposées sous la dernière réponse. Le format qui permet cette mise en forme est ajouté par le code aux consignes Questions (`antoine_ia_ask_format`, `lib/prompts.php`), comme pour Relecture et Brief. Si l'hébergement retient la réponse jusqu'à la fin (tampon ou compression), elle s'affiche d'un bloc, sans autre conséquence.
- **Vérifier les consignes envoyées** : sur le serveur, `php lib/test-prompt.php --tache=ask --question="…"` affiche les consignes construites, sans appeler Claude (options en tête du fichier). Les questions des agences ne sont jamais journalisées.
- **Analyse de la base** : `analyse.php` (lien dans `admin.php`, même mot de passe). Le bouton « Lancer l'analyse » demande à Claude des questions pour affiner la connaissance produit, des incohérences, des idées de compléments et les points encore ouverts. Le dernier rapport est conservé sur le serveur. Le serveur vérifie que les références et les extraits cités existent dans la base et marque d'un ⚠ ceux qu'il ne retrouve pas : ce sont des pistes à vérifier, pas des constats. Les consignes de l'analyse se trouvent dans une section « Analyse » de la page Notion « Consignes Claude » (à créer si vous voulez les modifier ; sinon `lib/consignes/analyse.md` est utilisé). Une analyse coûte un appel à Claude, compté dans la consommation. Pendant l'analyse, la page affiche les étapes (✓ une fois faites, avec leur durée) et une barre de progression ; celle-ci est une estimation, car la durée réelle dépend de Claude. On peut recharger la page : le suivi reprend.
- **Ajouter ou retirer une agence** : modifier la liste `agencies` de `config.php`.
- **Consommation** : visible dans `admin.php`, par mois et par agence. C'est une estimation ; le montant facturé est celui de la console Claude.
- **Données** : les contenus relus par les agences sont transmis à l'API Claude d'Anthropic, selon les conditions commerciales d'Anthropic.

## 7. Fonctions non reprises

L'analyse de site et la relecture d'une page web à partir de son adresse ne sont pas incluses : les agences collent le texte de la page dans l'onglet « Texte ».

## 8. Dépôt et déploiement

Le code est géré dans GitHub et déployé automatiquement (voir `CLAUDE.md`, section « Dépôt, déploiement et secrets »). Ne modifiez pas les fichiers directement sur le serveur, sauf `config.php`.
