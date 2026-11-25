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

## Téléchargement de la relecture (`lib/app.html`, `reviewDocx`)

Pour une relecture à partir d'un fichier .docx, le bouton de téléchargement fournit le document d'origine annoté, créé dans le navigateur (JSZip, chargé depuis jsDelivr comme mammoth) : le fichier n'est pas envoyé au serveur et `docBuf` n'en garde qu'une copie en mémoire. Texte collé : pas de document d'origine, le rapport reste en Markdown (`reviewMarkdown`).

- Un commentaire Word (auteur « Product AI (relecture) ») par remarque, ancré sur la citation ; les commentaires déjà présents dans le fichier sont conservés. La synthèse, les éléments à ajouter, les commentaires non rattachés et les pages de la base utilisées forment une page ajoutée en fin de document (`synthXml`).
- La citation est cherchée dans `word/document.xml` sans espaces, sans différence de guillemets ni de casse (`locateQuote`) : le texte transmis à Claude vient de mammoth, qui ne restitue pas les sauts de ligne comme le XML. Une citation longue retrouvée seulement par son début ou sa fin ancre le commentaire sur tout le paragraphe. Une citation introuvable (ou absente, ou dans une note de bas de page) va dans la liste « Commentaires non rattachés » de la page de fin.
- Si la création échoue, le rapport est téléchargé en Markdown avec un message.
- Toute modification du format de réponse de la relecture (`antoine_ia_review_format`) doit être reportée dans `reviewDocx`, `synthXml` et `reviewMarkdown`.
- Vérification d'un changement : annoter un vrai .docx (avec tableau, gras en milieu de phrase, commentaire existant), contrôler que chaque commentaire encadre le bon passage, puis ouvrir le fichier dans Word.

## Dépôt, déploiement et secrets

Le code vit dans le dépôt GitHub privé `thomaselancite/product-ai`. Toute modification passe par une branche et une pull request ; la fusion dans `main` déploie automatiquement sur l'hébergement OVH en SFTP (lftp), comme le dépôt `creation-site-web` (`.github/workflows/deploy.yml`). Le workflow peut aussi être lancé à la main (« Run workflow », simulation par défaut). `check-ovh.yml` teste la connexion et le dossier distant en lecture seule.

- Secrets du déploiement : secrets GitHub Actions `EC_SSH_HOST`, `EC_SSH_USER`, `EC_SSH_PASS`, `EC_SSH_DIR` (dossier de l'outil tel qu'indiqué dans l'onglet « Mes sites » OVH, souvent relatif) et, si besoin, `EC_SSH_PORT` (22 par défaut). Ne jamais les écrire dans le dépôt.
- `config.php` (clés Claude et Notion, mot de passe admin et mots de passe initiaux des agences, lus une seule fois pour créer les comptes) et `data/*.php` (cache, compteurs) ne sont pas dans le dépôt (`.gitignore`), ne sont jamais envoyés et ne sont jamais supprimés par le déploiement. `config.example.php` en est le modèle ; toute nouvelle clé de configuration doit y être ajoutée et signalée, car elle doit aussi être ajoutée à la main dans `config.php` sur le serveur.
- Le déploiement est un miroir (`mirror --reverse --delete`) : un fichier retiré du dépôt est supprimé du serveur. `EC_SSH_DIR` doit donc être un dossier dédié à cet outil. Le dossier distant n'est jamais créé automatiquement. Une simulation précède toujours l'envoi et arrête le déploiement si elle supprimerait `config.php` ou `data/*.php`.
- Après tout changement du workflow : lancer d'abord `check-ovh.yml`, puis le déploiement en simulation.
- Avant de fusionner : la vérification de syntaxe PHP du workflow doit être au vert.

## Design system et feuilles de style

Le style suit le design system « MonBudget » : thème clair uniquement, accent indigo, Inter, chiffres tabulaires, rayons de 8 px (contrôles) et 12 px (cartes). Les valeurs des couleurs ont été lues sur une capture du design system et validées telles quelles le 20/10/2026 ; la source fait foi si elles divergent.

- `assets/design.css` : jetons (`:root`) et composants communs (`.btn`, `.btn.secondary`, `.panel`, champs, `.hint`, `.error`, `.linkbtn`). Chargé par l'outil (`lib/app.html`) et par les pages d'administration. Toute couleur, tout rayon ou toute ombre nouveau passe par un jeton, jamais par une valeur en dur. Les anciens noms (`--asphalt`, `--bubble`, `--blue`, `--blue-soft`, `--focus`) sont des alias de `--accent` : ne pas les redéfinir.
- `assets/admin.css` : style des pages d'administration (sidebar, sections en cartes, messages, tableaux denses). Chargé après `design.css`.
- `lib/app.html` : le CSS propre à l'outil reste en ligne (sidebar `header.top.sidebar`, vues, panneau de relecture, conversation). Le thème sombre a été supprimé : ne pas le réintroduire sans décision explicite.
- Police à empattements (`--read`, Source Serif 4) : réservée au texte relu (`.doc`) ; les pages de la Base partagent cette classe et la gardent.
- Cache : `design.css` et `admin.css` sont appelées avec `?v=1`. À chaque modification de ces feuilles, incrémenter le numéro dans `lib/app.html` et dans `admin_head_links()` (`lib/admin_ui.php`), sinon les navigateurs gardent l'ancienne version.

Pages d'administration (`lib/admin_ui.php`) :
- Chaque page appelle `admin_head_links()` dans le `<head>`, `admin_shell_open($cle, $titre, $nav)` à l'ouverture du corps, puis `admin_shell_close()` à la fin. Une nouvelle page d'administration doit être ajoutée à la liste `$items` de `admin_shell_open()`.
- Les règles CSS propres à une page restent dans son `<style>`, avec des variables de `design.css`. Les icônes de la sidebar d'administration utilisent `.nav-ico` ; `.ico` est réservée au suivi d'avancement (`progress_css()`).

Règles de modification :
- `lib/app.html` a des fins de ligne CRLF (les autres fichiers sont en LF) : les conserver, sous peine de réécrire tout le fichier dans la PR.
- Le JavaScript de `lib/app.html` construit du HTML avec des classes et identifiants précis (`.card`, `.tag`, `mark.hl`, `.src-pill`, `.st`, `#counts`, `#verdict`…) : toute modification de style doit les conserver. Les couleurs du verdict de relecture sont en dur dans le JavaScript (objet `VERDICT`) : les aligner sur `--green`, `--amber-bar` et `--red`.
- Vérification d'un changement de style : afficher les écrans avec une API simulée (outil) ou avec `php -S` et une configuration factice (administration), sur bureau et à 390 px, avant d'ouvrir la PR.

## Analyse de la base (`analyse.php`)

Page réservée à l'administrateur (session d'un compte administrateur, jeton CSRF). Un appel à Claude sur la base entière, pages « À valider » comprises et pages « Obsolète » exclues, produit un rapport : questions pour affiner, incohérences, idées de compléments, points ouverts. Le dernier rapport est conservé dans `data/analyse.php` (non déployé, non versionné) ; la page signale quand la base a changé depuis (empreinte du texte transmis à Claude).

- Consignes : section « Analyse » de la page Notion « Consignes Claude » (clé `analyse` de `antoine_ia_parse_consignes`), avec repli sur `lib/consignes/analyse.md`. Le format de réponse JSON est dans `lib/prompts.php` (`antoine_ia_analysis_format`) : toute modification doit être reportée dans `analyse.php` et dans `antoine_ia_analysis_check`.
- Contrôle sans second appel à Claude (`antoine_ia_analysis_check`) : chaque référence « Page > Section » et chaque extrait d'incohérence est recherché dans la base, à la mise en forme près ; ce qui n'est pas retrouvé est marqué « ⚠ ». Le rapport reste une liste de pistes à vérifier : rien n'est écrit dans Notion, les corrections passent par le projet Claude.
- La consommation est comptée sous « Admin (analyse) » et soumise au budget mensuel ; pas de limite horaire. Un verrou (`data/analyse.lock.php`) évite deux analyses simultanées.
- Suivi d'avancement : l'analyse s'exécute dans une requête POST (`?action=run`) qui écrit chaque étape dans `data/analyse.progress.php` (écriture atomique) ; la page l'interroge chaque seconde (`?action=progress`). Le suivi (classe `RunProgress`, verrou, détection d'interruption, panneau HTML/JS) est partagé avec `cartographie.php` dans `lib/progress.php`. La barre est une estimation (le temps passé chez Claude est deviné d'après les sections JSON déjà reçues). Une analyse dont le processus a disparu est signalée « interrompue » (verrou libre alors que l'état reste « en cours »). La session est fermée (`session_write_close`) pour ne pas bloquer les interrogations. Toute étape ajoutée doit l'être dans `analyse_steps` et dans `analyse_run`. La détection « interrompue » exige un verrou libre ET aucune mise à jour depuis 150 s (le verrou seul n'est pas fiable sur tous les hébergements) ; un arrêt brutal de PHP est inscrit dans le panneau par une fonction d'arrêt, et les exceptions inattendues affichent leur cause (page admin). Si la réponse de Claude est coupée (`stop_reason` = `max_tokens`, lu via le 6e paramètre facultatif de `antoine_ia_claude_stream`) ou n'est pas un JSON valide, le texte reçu est conservé dans `data/analyse.brut.php` (serveur seulement) et le panneau donne la cause ; `max_tokens` (config.php) couvre la réflexion et la réponse.
- À chaque modification de la page Notion « Consignes Claude », resynchroniser aussi `lib/consignes/analyse.md`.

## Cartographie des sujets manquants (`cartographie.php`)

Page réservée à l'administrateur, lecture seule, même mécanique que `analyse.php` (suivi dans `lib/progress.php`, fichiers `data/cartographie.progress.php`, `data/cartographie.lock.php`, `data/cartographie.php` pour le dernier résultat, `data/cartographie.brut.php` si la réponse est coupée ou illisible ; tous non versionnés et non déployés). Un appel à Claude sur la base entière (pages « À valider » comprises, « Obsolète » exclues) renvoie, par produit, les sujets manquants (`sujet`, `categorie`, `importance`, `marches`, `pourquoi`, `reference`) et une liste unique de catégories définie par Claude.

- Consignes : section « Cartographie » de la page Notion « Consignes Claude » (clé `cartographie` de `antoine_ia_parse_consignes`), repli sur `lib/consignes/cartographie.md`. Format JSON dans `antoine_ia_map_format` ; toute modification doit être reportée dans `antoine_ia_map_check` et dans `cartographie.php` (affichage). À chaque modification de la section Notion, resynchroniser `lib/consignes/cartographie.md`.
- Les marchés commercialisés et documentés de chaque produit viennent des propriétés Notion « Marchés » et « Marchés documentés » (faits), jamais de Claude. `antoine_ia_map_check` valide les marchés cités par Claude (liste `antoine_ia_markets`, marché non couvert par le produit écarté et signalé), rattache les catégories à la liste (sinon ⚠), vérifie les références (`antoine_ia_check_ref`) et ajoute une ligne par produit de la base, même si Claude l'a oublié (⚠ non analysé).
- La vue par marché est calculée par le serveur à partir du même résultat (pas de second appel). Consommation comptée sous « Admin (cartographie) », soumise au budget mensuel. La réponse est longue : `max_tokens` (config.php) doit être suffisant ; Claude est limité à 10 sujets par produit, le serveur n'en garde que 15.

## Cartographie du glossaire de traduction (`glossaire.php`, `lib/glossaire.php`)

Page réservée à l'administrateur, lecture seule (rien n'est écrit dans Notion). Deux parties.

- Partie 1, sans Claude, recalculée à chaque affichage d'après la copie de la base (`data/kb.php`) : `antoine_ia_glossary_parse` lit le Markdown de la page transverse dont le nom contient « glossaire », `antoine_ia_glossary_stats` calcule sections × langues, termes × langues (grille, filtre « lacunes seulement ») et langues × sources. **Dépendance au format de la page Notion** : titres de niveau 2 par section ; tableaux `Terme FR | UK | US | DE | ES | IT | Sources` (une colonne « UK et US » remplit les deux langues) ; « — » = aucun terme sourcé ; plusieurs termes dans une case séparés par « ; » ; ⚠ dans une case = point d'attention ; sections « par pays » en liste `- **France** : …` (éléments comptés par « » ou virgules, « aucune source » = 0) ; liste de codes dans « Codes de sources » (`- **S1, S2** : description`). Les colonnes ou lignes non reconnues sont signalées en haut de page, jamais ignorées en silence. La vue langues × sources est une approximation : la colonne « Sources » est par ligne, pas par langue. Si la mise en forme de la page change, adapter `lib/glossaire.php`.
- Partie 2, un appel à Claude (même mécanique que `cartographie.php` : `lib/progress.php`, fichiers `data/glossaire.progress.php`, `data/glossaire.lock.php`, `data/glossaire.php` pour le dernier résultat, `data/glossaire.brut.php` si la réponse est coupée ou illisible ; non versionnés et non déployés) : termes utilisés dans les pages produit et marque mais absents du glossaire. Consignes : section facultative « Glossaire » de la page Notion « Consignes Claude » (clé `glossaire`), repli sur `lib/consignes/glossaire.md` (à resynchroniser à chaque modification de la section Notion). Format JSON dans `antoine_ia_glossary_format` ; contrôle par `antoine_ia_glossary_check` : terme déjà au glossaire, doublon ou référence vers le glossaire lui-même → écarté ; langue inconnue, référence ou extrait introuvable, terme absent de l'extrait → ⚠. Aucune traduction n'est demandée à Claude. Consommation comptée sous « Admin (glossaire) », soumise au budget mensuel.

## Affirmations à confirmer (`affirmations.php`, `lib/affirmations.php`)

Page réservée à l'administrateur, lecture seule vis-à-vis de Notion (jamais d'écriture dans Notion, jamais de passage d'une page à « Validé »). Claude déduit, pour UNE page choisie, au plus 25 affirmations (`affirmation`, `extrait` mot pour mot, `reference`) ; le PO pose Confirmée / Infirmée / À préciser (+ commentaire). Un seul compte admin : les verdicts ne sont pas attribués à une personne.

- Stockage : `data/affirmations.php` (`items` indexés par identifiant = sha1 de page + extrait normalisé, `runs`, `last_page`), non versionné et non déployé ; sauvegarde par `?action=export` (JSON). Écritures par `antoine_update('affirmations')`. **Le suivi d'avancement de la génération s'appelle `affirmgen` (et non `affirmations`)** : le verrou du suivi (`data/<nom>.lock.php`) est le même fichier que celui d'`antoine_update`, le même nom provoquerait un blocage.
- Contrôle serveur à la génération (`antoine_claims_check`) : la référence doit viser la page choisie, l'extrait doit se trouver dans cette page (à la mise en forme près), pas de doublon (par identifiant, ni par texte avec une affirmation non archivée) ; sinon écartée et comptée par motif dans le résumé.
- Détection des changements, sans Claude et à chaque affichage (`antoine_claim_state`) : extrait toujours dans la copie de la base → « ok » ; introuvable → « à revoir » (verdict conservé mais figé, avant/après affichés, archivage proposé) ; page absente → « page absente ». Limite connue : une modification hors de l'extrait n'est pas détectée, une reformulation sans changement de sens est signalée.
- Consignes : section facultative « Affirmations » de la page Notion « Consignes Claude », repli sur `lib/consignes/affirmations.md` (à resynchroniser). Consommation sous « Admin (affirmations) », soumise au budget mensuel. `progress_js` accepte `fields` (champs de formulaire envoyés en plus, ici `page`).

## Authentification

- Un seul système : comptes nominatifs (identifiant en lettres/chiffres minuscules, 3 à 32 caractères + mot de passe), rôles `admin` et `agency`. Code dans `lib/auth.php`, inclus par `lib/core.php`.
- Les comptes sont dans `data/accounts` (mots de passe hachés avec `password_hash`). Au premier appel, ils sont créés depuis `config.php` (`admin_password` → compte `admin`, `agencies` → un compte par agence, ancien code = mot de passe initial) ; ces clés ne sont plus relues ensuite. Les comptes se gèrent dans `comptes.php`.
- Secours : tant qu'aucun admin actif n'existe, `admin` + `admin_password` de `config.php` ouvre une session.
- Sessions : cookie HttpOnly `antoine_sid`, jeton aléatoire stocké haché (SHA-256) dans `data/sessions`, 14 jours glissants. Pas de session PHP native.
- CSRF : jeton lié à la session (`antoine_csrf()`) ; champ `csrf` des formulaires, en-tête `X-Antoine-Csrf` pour l'API sur toute requête non GET.
- Essais de connexion limités (5 échecs / 15 min par IP + identifiant).
- Toute nouvelle page d'administration : `antoine_admin_user()` en tête (sinon redirection vers `admin.php`), `antoine_csrf()` sur les formulaires, entrée ajoutée dans `lib/admin_ui.php`.
- Toute nouvelle action d'API passe après `antoine_session_user()` ; `$agency` vient du compte, jamais de la requête.
- Jamais de mot de passe en clair dans le dépôt, Notion ou le chat : un mot de passe généré n'est affiché qu'une fois, dans la page Comptes.
