# ElanCité Product AI — règles du dépôt

## Base de connaissance (Notion)

Base de connaissance : l'outil charge la base Produits et les sous-pages directes (un seul niveau) de la page d'accueil Notion, sauf Consignes Claude, lue séparément par ses sections Relecture, Brief et Questions. Pour les pages hors base Produits, le statut se lit dans l'encadré de tête (mention "À valider"). Une nouvelle sous-page directe de la page d'accueil est prise en compte sans modification du code.

Mise en œuvre (`lib/notion.php`, fonction `antoine_ia_load_kb`) :
- Sous-pages : blocs `child_page` renvoyés par `GET /v1/blocks/{accueil}/children`, dans l'ordre de Notion. Ignorés : la base Produits (`child_database`), les liens vers d'autres pages, les pages dans la corbeille, les pages posées dans des colonnes ou des blocs dépliables.
- Marque & positionnement garde sa place dédiée (`notion_brand`) ; les autres sous-pages sont rangées dans `transverses`.
- Consignes Claude est exclue par son identifiant (`notion_prompts`) ou, à défaut, par son titre exact.
- Statut : « À valider » si le premier encadré contient « valider », sinon « Validé » (`antoine_ia_status_from_encadre`). Ces pages sont toujours transmises à Claude avec leur statut ; seules les pages produits sont filtrées par statut.
- Le code ne fait que lire Notion ; il n'écrit jamais dedans.
- Cache : `data/kb.php`, `cache_minutes` (10 min) ; le bouton « Relire la base Notion maintenant » d'`admin.php` force la relecture.

## Contenu transmis et consignes de repli

Le contenu Notion transmis à Claude est désespacé, avec les mêmes règles que les consignes. Les fichiers lib/consignes/*.md sont une copie de secours de la page Notion Consignes Claude : ils doivent être resynchronisés à chaque modification significative de cette page.

Mise en œuvre : `antoine_ia_unescape` (`lib/notion.php`), appliquée par `antoine_ia_md_convert` au contenu des pages et à leur encadré, sauf `\|` (tableaux Markdown) ; Consignes Claude reste désespacée par `antoine_ia_parse_consignes`.

## Format de réponse et affichage des Questions

Le format de réponse des trois tâches reste dans le code (`lib/prompts.php`) : celui de Questions (`antoine_ia_ask_format`) alimente l'affichage en pastilles, encarts et relances de l'onglet Questions. Toute modification de ce format doit être reportée dans `parseAnswer` (`lib/app.html`).

## Dépôt, déploiement et secrets

Le code vit dans le dépôt GitHub privé `thomaselancite/product-ai`. Toute modification passe par une branche et une pull request ; la fusion dans `main` déploie automatiquement sur l'hébergement OVH en SFTP (lftp), comme le dépôt `creation-site-web` (`.github/workflows/deploy.yml`). Le workflow peut aussi être lancé à la main (« Run workflow », simulation par défaut). `check-ovh.yml` teste la connexion et le dossier distant en lecture seule.

- Secrets du déploiement : secrets GitHub Actions `EC_SSH_HOST`, `EC_SSH_USER`, `EC_SSH_PASS`, `EC_SSH_DIR` (dossier de l'outil tel qu'indiqué dans l'onglet « Mes sites » OVH, souvent relatif) et, si besoin, `EC_SSH_PORT` (22 par défaut). Ne jamais les écrire dans le dépôt.
- `config.php` (clés Claude et Notion, mot de passe admin, codes des agences) et `data/*.php` (cache, compteurs) ne sont pas dans le dépôt (`.gitignore`), ne sont jamais envoyés et ne sont jamais supprimés par le déploiement. `config.example.php` en est le modèle ; toute nouvelle clé de configuration doit y être ajoutée et signalée, car elle doit aussi être ajoutée à la main dans `config.php` sur le serveur.
- Le déploiement est un miroir (`mirror --reverse --delete`) : un fichier retiré du dépôt est supprimé du serveur. `EC_SSH_DIR` doit donc être un dossier dédié à cet outil. Le dossier distant n'est jamais créé automatiquement. Une simulation précède toujours l'envoi et arrête le déploiement si elle supprimerait `config.php` ou `data/*.php`.
- Après tout changement du workflow : lancer d'abord `check-ovh.yml`, puis le déploiement en simulation.
- Avant de fusionner : la vérification de syntaxe PHP du workflow doit être au vert.
