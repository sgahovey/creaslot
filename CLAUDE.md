# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository. Il est volontairement complet et auto-suffisant : il regroupe le contexte du projet, les critères du référentiel CDA, la manière de coder attendue et les références documentaires officielles.

## Projet

CreaSlot — application web de gestion de rendez-vous pour le Cnam Réunion (mémoire MSP3, Concepteur Développeur d'Applications). Les Auditeurs (étudiants) réservent des créneaux proposés par le Personnel administratif. Trois types de RDV : présentiel, téléphone, visio.

Stack : PHP 8.4 / Symfony 8 (pas 7.4 LTS — choix assumé) / Doctrine ORM 3 / MySQL 8 / Twig 3 / Bootstrap 5. Pas de Redis, pas de NoSQL — sur-ingénierie pour le besoin. Tout passe par Docker.

## Critères du référentiel CDA à satisfaire (le jury les coche)

Le code et les livrables doivent matérialiser ces critères d'évaluation. À garder en tête à chaque génération de code :

**Développer des composants métier** : bonnes pratiques POO respectées ; composants serveurs sécurisés ; règles de nommage conformes aux normes de qualité ; code source documenté ; tests unitaires réalisés ; tests de sécurité réalisés.

**Architecture logicielle** : architecture multicouche répartie et sécurisée ; rôle de chaque couche clairement défini en tenant compte de la stratégie de sécurité ; besoins d'éco-conception identifiés.

**Base de données** : schéma conceptuel respectant le relationnel ; schéma physique conforme au cahier des charges ; règles de nommage respectées ; intégrité, sécurité et confidentialité des données assurées ; base de test avec jeu d'essai complet et restaurable.

**Interfaces utilisateur** : conformes au dossier de conception ; adaptées au support (responsive) ; charte graphique respectée ; réglementation respectée (accessibilité RGAA) ; tests unitaires des composants concernés.

**Gestion de projet / DevOps** : tâches planifiées ; procédures qualité mises en œuvre ; outils de gestion de versions et de collaboration installés ; outils collaboratifs choisis selon la méthode (agile) ; intégration et déploiement continus (CI/CD).

**Transverses obligatoires** : recommandations ANSSI, RGPD (CNIL), RGAA (accessibilité), écoconception des services numériques.

## Commandes

Tout s'exécute dans le conteneur `app` via `docker compose exec`. Le projet vit dans WSL (`~/creaslot`) : Claude Code doit être lancé **depuis WSL**, pas depuis Windows — sinon les chemins `\\wsl.localhost\...` cassent Git.

```bash
# Démarrage de l'environnement (nginx + app + db + phpmyadmin)
docker compose up -d
docker compose ps                    # vérifier que les services sont healthy

# Application : http://localhost:8000
# phpMyAdmin : http://localhost:8080

# Symfony console
docker compose exec app php bin/console <commande>
docker compose exec app php bin/console cache:clear
docker compose exec app php bin/console doctrine:migrations:migrate
docker compose exec app php bin/console doctrine:fixtures:load
docker compose exec app php bin/console make:migration       # après modif d'entité
docker compose exec app php bin/console asset-map:compile    # OBLIGATOIRE après toute modif JS/CSS (voir ci-dessous)

# Tests PHPUnit (la suite est dans tests/, bootstrap tests/bootstrap.php, APP_ENV=test forcé)
docker compose exec app php bin/phpunit
docker compose exec app php bin/phpunit tests/Service/SlotServiceTest.php
docker compose exec app php bin/phpunit --filter test_checkOverlap_avecCreneauChevauchant
docker compose exec app php bin/phpunit --coverage-html var/coverage

# Composer
docker compose exec app composer install
docker compose exec app composer dump-autoload --optimize

# Reset complet de la BDD locale
docker compose down -v && docker compose up -d
```

`phpunit.dist.xml` a `failOnDeprecation/Notice/Warning="true"` — toute deprecation Symfony/Doctrine fait échouer la suite. Les corriger plutôt que de les masquer.

## Architecture

### Modèle de données (8 entités, src/Entity/)

- `Utilisateur` (table `utilisateur`) — implémente `UserInterface` ; champ `role` (enum `RoleUtilisateur`), pas d'héritage Doctrine. Mot de passe hashé en argon2id (`mot_de_passe_hash`).
- `Service` — rattachement organisationnel du Personnel.
- `TypeRdv` — présentiel / visio / téléphone, avec `couleur_hex` stockée en BDD et lue dynamiquement par Twig.
- `Creneau` — appartient à un Utilisateur (Personnel), caractérisé par un TypeRdv.
- `Reservation` — un Auditeur réserve un Creneau ; statut enum `StatutReservation` (ACTIVE / ANNULEE).
- `Notification` (table `notification`) — notification in-app destinée à un `Utilisateur` (`id_destinataire`), typée par l'enum `TypeNotification`, avec drapeau `lu` (US-4.7).
- `JournalAdmin` (table `journal_admin`) — trace immuable des actions d'administration sur les comptes (enum `TypeActionJournal`) ; acteur et cible figés en scalaires (`acteur_id`/`cible_id` + libellés), pas de FK, pour survivre à la suppression des comptes (US-5.5, RGPD).
- `ResetPasswordRequest` (table `reset_password_request`) — demande de réinitialisation de mot de passe (SymfonyCasts ResetPasswordBundle) ; jeton haché, `id_utilisateur` non nullable, usage unique (US-6.2).

Conventions BDD : tables `snake_case` singulier, foreign keys toujours `id_<entité>` (`id_utilisateur`, `id_creneau`), clés primaires `INT AUTO_INCREMENT` (cf. migrations).

### Rôles et autorisations

Trois rôles dans `RoleUtilisateur` : `ROLE_AUDITEUR`, `ROLE_PERSONNEL`, `ROLE_SUPER_ADMIN`. Hiérarchie dans `config/packages/security.yaml` : SUPER_ADMIN ⊃ PERSONNEL ⊃ AUDITEUR.

Contrôleurs organisés par rôle :
- `src/Controller/Auditeur/` — parcours étudiant (`#[IsGranted('ROLE_AUDITEUR')]` au niveau classe)
- `src/Controller/Personnel/` — parcours Cnam (`#[IsGranted('ROLE_PERSONNEL')]`)
- `src/Controller/Api/` — endpoints JSON pour le calendrier Personnel

Autorisations contextuelles via Voters Symfony (`src/Security/`) :
- `CreneauVoter` — constantes `VIEW`, `EDIT`, `DELETE`. Seul le créateur ou SUPER_ADMIN peut modifier.
- `ReservationVoter`, `UtilisateurVoter` — même pattern.
- `UserChecker` — branche `est_actif` au login.

Routes : préfixe `app_` (ou `api_`), URLs en français kebab-case (`/creneau/nouveau`, `/mes-reservations`).

### Concurrence sur les réservations (point critique)

Deux Auditeurs peuvent cliquer "Réserver" sur le même créneau en même temps. La création de réservation utilise un verrouillage pessimiste MySQL :

```php
$em->beginTransaction();
$em->lock($creneau, LockMode::PESSIMISTIC_WRITE);
$em->refresh($creneau);
// re-vérifier que le créneau est encore disponible après le lock
// puis persist + flush + commit, sinon rollback
```

Voir `src/Controller/Auditeur/ReservationController::enregistrerReservation()`. Toute modification du chemin de réservation doit préserver ce pattern (transaction explicite + `PESSIMISTIC_WRITE` + re-check après refresh).

Pour les créneaux côté Personnel, `SlotService::detecteChevauchements()` interroge `CreneauRepository::findChevauchements()` (intersection stricte `]debut, fin[`) pour empêcher la création de créneaux qui se chevauchent pour un même Personnel.

### Frontend

- Templates Twig dans `templates/` organisés par rôle (`auditeur/`, `personnel/`), plus `_partials/` (fragments inclus), `components/` (composants paramétrables), `auth/`.
- Bandeau d'environnement affiché via `_partials/bandeau_environnement.html.twig` piloté par `APP_ENVIRONMENT_LABEL` (orange en preprod, masqué en prod).
- AssetMapper + Stimulus + Turbo (pas de bundler externe, pas de Node).
- **Toujours exécuter `php bin/console asset-map:compile` après toute modification JS/CSS** : la config Docker ne sert pas les assets à la volée en dev, donc une modif non compilée n'est pas prise en compte par le navigateur.
- Contrôleurs Stimulus dans `assets/controllers/`. Privilégier les attributs `data-*` (`data-controller`, `data-action`, `data-*-target`, `data-*-value`) plutôt que du JS inline. Ne pas introduire de dépendance npm/Webpack : passer par `importmap.php` et les packages Symfony UX.
- **Charte graphique** : `docs/design-tokens.md` est la source de vérité unique. Utiliser les variables CSS `--cs-*` plutôt que des codes hex en dur ; préfixer les classes custom par `cs-`.
- **FullCalendar v6** : chargé via le bundle global `index.global.min.js` vendorisé dans `assets/vendor/` (core + plugins + preact en un seul fichier au linking interne cohérent). NE PAS utiliser l'ESM jsDelivr (`@fullcalendar/*` + `preact` éclatés) : il casse le linking interne et provoque l'erreur `Class constructor component cannot be invoked without 'new'`. Usage via le global `window.FullCalendar` (`const { Calendar } = window.FullCalendar;`), jamais `import { Calendar } from '@fullcalendar/core'`. Locale FR : vendoriser aussi `@fullcalendar/core/locales/fr.global.min.js`.
- **Indicateurs de chargement** : utiliser le spinner natif Bootstrap (`.spinner-border` / `.spinner-grow`) avec `role="status"` + texte `visually-hidden` (accessibilité RGAA). Ne pas introduire de composant Tailwind/shadcn (incompatible avec la charte Bootstrap).

## Conventions de code (PHP / Symfony)

- `declare(strict_types=1);` en tête de chaque fichier PHP.
- Style : **PER Coding Style** (successeur de PSR-12) ; autoloading **PSR-4** sur le namespace `App\`. Indentation 4 espaces, fins de ligne LF (cf. `.editorconfig`). Le style est désormais **outillé** : PHP-CS-Fixer (`@PSR12` + `@Symfony`, `setRiskyAllowed(false)`) avec 4 surcharges maison assumées (tests en `snake_case`, concaténation espacée, pas de Yoda, alignement des `=>` conservé), et l'analyse statique **PHPStan niveau 8 sans baseline** (cf. section « Outils qualité & CI »).
- Attributs PHP 8 partout (`#[ORM\Entity]`, `#[Route]`, `#[IsGranted]`) — jamais d'annotations ni de mapping YAML.
- Typage strict : tout paramètre, retour et propriété est typé. Pas de type `mixed` sans raison.
- Injection par constructor (`readonly`, promoted properties). Pas d'accès statique à un service.
- Enums PHP 8.1+ plutôt que constantes de classe.
- Repositories étendent `ServiceEntityRepository`.
- Accès aux données via QueryBuilder / DQL paramétré uniquement — jamais de SQL concaténé (prévention injection SQL).
- Logique métier dans des Services (`src/Service/`), pas dans les contrôleurs. Un contrôleur reste mince : il reçoit, délègue, répond.
- Préférer les exceptions métier custom (`OverlappingSlotException`, `ConflictingReservationException`) aux codes retour ou null.
- Noms en français côté métier : `creneau`, `reservation`, `auditeur`, `personnel`, `detecteChevauchements()`. Pas d'abréviations (`$u`, `$r`).

## Manière de coder — Clean Code (d'après *Coder proprement*, R. C. Martin)

### Noms
- Noms révélateurs d'intention : le nom dit pourquoi la chose existe et ce qu'elle fait. Un nom long et descriptif vaut mieux qu'un nom court énigmatique + commentaire.
- Un mot par concept, partout (`recupere`/`get` : ne pas mélanger). Cohérence : même intention → même nom (principe de moindre surprise).
- Noms prononçables et recherchables ; pas de noms d'une seule lettre hors variables de boucle très locales.
- Pas de notation hongroise ni de préfixes parasites.

### Fonctions
- **Courtes**, et faisant **une seule chose** : si on peut en extraire une autre fonction dont le nom n'est pas une simple reformulation, c'est qu'elle en faisait plusieurs.
- **Un seul niveau d'abstraction par fonction** ; ne pas mélanger haut niveau et détails.
- Lecture de haut en bas (règle de décroissance) : chaque fonction est suivie de celles d'un niveau d'abstraction juste inférieur.
- **Arguments** : idéalement 0, sinon 1, sinon 2. Trois à éviter, plus de trois jamais sans très bonne raison. Regrouper les arguments liés dans un objet/DTO.
- Pas d'**argument indicateur** (booléen) : il signale que la fonction fait deux choses → la scinder.
- Pas d'**argument de sortie** : si la fonction modifie un état, que ce soit celui de son objet.
- **Séparer commande et requête** : une fonction modifie un état OU retourne une information, pas les deux.
- Préférer les **exceptions** aux codes d'erreur ; ne pas retourner ni passer `null`.

### Commentaires
- Un bon nom vaut mieux qu'un commentaire. Les commentaires expliquent le **pourquoi**, jamais le **quoi** (le code dit le quoi).
- **Pas de code commenté** : Git tient l'historique, on supprime.
- Pas de commentaires obsolètes, redondants, ni de journaux de modification (le VCS s'en charge).

### Mise en forme
- Concepts liés proches verticalement ; variables déclarées au plus près de leur usage.
- Indentation systématique, jamais de portée réduite à une ligne sans accolades.
- Style cohérent dans tout le projet (formaté par l'outillage, cf. PHP-CS-Fixer recommandé).

### Conditions & structure
- Encapsuler les expressions conditionnelles dans des fonctions intentionnelles : `if (creneauEstReservable($creneau))` plutôt qu'une conjonction de tests bruts.
- Éviter les conditions négatives : préférer la forme positive.
- Préférer le polymorphisme (enums, classes) aux `switch`/`if-else` répétés ; privilégier la structure à la convention.
- Remplacer les nombres magiques par des constantes nommées.
- Encapsuler les conditions aux limites dans des variables explicatives.

### Heuristiques générales
- **DRY** : pas de duplication ; factoriser dans un Service.
- **Couplage faible** : interfaces concises, cacher l'implémentation, limiter les variables et méthodes exposées.
- Supprimer le **code mort** (branches jamais atteintes, méthodes jamais appelées) et le **désordre** (constructeurs vides, variables inutilisées).
- Éviter le couplage artificiel et la navigation transitive (loi de Déméter).
- Les noms de fonctions indiquent leur rôle ; comprendre l'algorithme avant de le « faire marcher » par tâtonnement.

## Sécurité (OWASP — exigence du référentiel)

- Valider toutes les entrées via le composant Validator (contraintes sur DTO/entités) avant tout traitement.
- Protection CSRF active sur tous les formulaires (comportement Symfony par défaut — ne pas désactiver).
- Échappement de sortie : laisser Twig échapper automatiquement ; ne jamais utiliser `|raw` sur des données utilisateur.
- Accès données paramétré uniquement → anti-injection SQL.
- Autorisation systématique via Voters pour toute action sur une ressource (ne pas se contenter d'un contrôle de rôle quand l'appartenance compte).
- Aucun secret en dur ni commité : tout dans `.env.local` / variables d'environnement (non versionnées).
- Mots de passe en argon2id (déjà en place). Messages d'authentification neutres (cf. Wording).
- Tests de sécurité : couvrir les accès refusés (Voters) et les tentatives d'accès inter-rôles.

## Accessibilité (RGAA)

- HTML sémantique (`<button>`, `<nav>`, `<main>`, titres hiérarchisés) plutôt que des `<div>` cliquables.
- Chaque champ de formulaire a un `<label>` associé ; chaque image porteuse de sens a un `alt` pertinent.
- Contrastes suffisants (respecter les tokens `--cs-*` de `docs/design-tokens.md`).
- Navigation au clavier possible partout ; attributs ARIA uniquement quand le HTML natif ne suffit pas.

## Journalisation (Monolog)

- Tracer les événements métier sensibles (login, réservation, annulation) et toutes les erreurs, avec un contexte utile (id ressource, id utilisateur).
- Niveaux appropriés : `info` métier, `warning` situations anormales récupérables, `error` exceptions.
- Ne jamais journaliser de mot de passe, de secret ni de donnée personnelle en clair (RGPD).

## Wording utilisateur

- **Français, vouvoiement systématique.** Pas d'anglais dans l'UI.
- Messages d'auth volontairement neutres : « Identifiants incorrects. » — jamais révéler si l'email existe.
- Messages flash via les canaux Symfony : `success`, `info`, `warning`, `error`.

## Git et commits

- Conventional Commits **en français à l'impératif** : `feat(scope): ajoute la recherche de créneaux disponibles`. Pas de majuscule initiale, pas de point final, ≤72 caractères.
- Scopes définis : `auth`, `creneau`, `reservation`, `notification`, `admin`, `dashboard`, `api`, `db`, `infra`, `deps`.
- Footer obligatoire pour les features : `Refs US-X.Y`.
- **Ne pas ajouter** de ligne `Co-authored-by: Cursor` / `Claude` / autre attribution AI — l'historique doit rester signé par `sgahovey` pour la soutenance MSP3.
- Workflow : `feature/US-X.Y-*` → PR vers `develop` → merge `develop` → `preprod` en fin d'itération → `preprod` → `main` une seule fois en fin de projet.

## Definition of Done (avant de fusionner une PR)

- La suite PHPUnit passe, sans aucune deprecation/notice/warning.
- PHP-CS-Fixer `--dry-run` ne signale aucun écart de style.
- PHPStan niveau 8 ne remonte aucune erreur (sans baseline).
- Les tests unitaires couvrent la logique métier ajoutée ; les chemins de sécurité (Voters) sont testés.
- Migration Doctrine incluse si le schéma a changé.
- Le code respecte les conventions ci-dessus (nommage, sécurité, accessibilité, Clean Code).
- Commit(s) au format Conventional Commits, avec `Refs US-X.Y`.

## Documentation officielle de référence

Avant de générer ou modifier du code sur un sujet technique, se référer à la documentation officielle de la version exacte utilisée ici. (Fichier compagnon : `docs/CreaSlot_References_Documentaires.md`, qui relie chaque ressource aux critères du référentiel CDA.)

**Langage & framework**
- PHP 8.4 (FR) — https://www.php.net/manual/fr/ · nouveautés 8.4 — https://www.php.net/manual/fr/migration84.php
- Symfony 8 — https://symfony.com/doc/current/index.html · Best Practices — https://symfony.com/doc/current/best_practices.html

**Persistance**
- Doctrine ORM 3 — https://www.doctrine-project.org/projects/doctrine-orm/en/current/index.html · Best Practices — https://www.doctrine-project.org/projects/doctrine-orm/en/current/reference/best-practices.html
- Doctrine DBAL 4 — https://www.doctrine-project.org/projects/doctrine-dbal/en/current/index.html · Migrations — https://www.doctrine-project.org/projects/doctrine-migrations/en/current/index.html
- MySQL 8.0 — https://dev.mysql.com/doc/refman/8.0/en/

**Composants métier Symfony**
- Security & Voters — https://symfony.com/doc/current/security.html · https://symfony.com/doc/current/security/voters.html
- Forms — https://symfony.com/doc/current/forms.html · Validator — https://symfony.com/doc/current/validation.html
- Messenger — https://symfony.com/doc/current/messenger.html · Mailer — https://symfony.com/doc/current/mailer.html · Notifier — https://symfony.com/doc/current/notifier.html
- Console — https://symfony.com/doc/current/console.html · EventDispatcher — https://symfony.com/doc/current/event_dispatcher.html

**Front-end (AssetMapper / Stimulus / Turbo)**
- AssetMapper — https://symfony.com/doc/current/frontend/asset_mapper.html
- StimulusBundle — https://symfony.com/bundles/StimulusBundle/current/index.html · Symfony UX — https://ux.symfony.com/
- Stimulus (Hotwire) — Reference : https://stimulus.hotwired.dev/reference/controllers (actions, targets, values, lifecycle)
- UX Turbo — https://symfony.com/bundles/ux-turbo/current/index.html · Turbo — https://turbo.hotwired.dev/
- Twig 3 — https://twig.symfony.com/doc/3.x/
- FullCalendar v6 — Getting Started : https://fullcalendar.io/docs/getting-started · Bundle global (script tags) : https://fullcalendar.io/docs/initialize-globals
- Bootstrap 5 — Spinners : https://getbootstrap.com/docs/5.3/components/spinners/

**Tests**
- PHPUnit 13.1 — https://docs.phpunit.de/en/13.1/ · Symfony Testing — https://symfony.com/doc/current/testing.html
- DoctrineFixturesBundle — https://symfony.com/bundles/DoctrineFixturesBundle/current/index.html

**Standards, Git & sécurité**
- PER Coding Style — https://www.php-fig.org/per/coding-style/ · PSR-4 — https://www.php-fig.org/psr/psr-4/
- Conventional Commits (FR) — https://www.conventionalcommits.org/fr/v1.0.0/
- GitHub : pull requests — https://docs.github.com/en/pull-requests · méthodes de merge (dont squash) — https://docs.github.com/en/repositories/configuring-branches-and-merges-in-your-repository/configuring-pull-request-merges/about-merge-methods-on-github · GitHub Actions — https://docs.github.com/en/actions
- OWASP Top 10 — https://owasp.org/www-project-top-ten/ · Cheat Sheets — https://cheatsheetseries.owasp.org/
- RGPD (CNIL) — https://www.cnil.fr/fr/la-cnil-publie-une-nouvelle-version-de-son-guide-rgpd-pour-les-developpeurs · RGAA — https://accessibilite.numerique.gouv.fr/ · RGESN (écoconception) — https://ecoresponsable.numerique.gouv.fr/

**Gestion de projet (Trello & agile)**
- Trello — ajouter/éditer une carte : https://support.atlassian.com/trello/docs/adding-cards/
- Trello — checklists (support de la DoD) : https://support.atlassian.com/trello/docs/adding-checklists-to-cards/
- Trello — mise en forme du texte (Markdown) : https://support.atlassian.com/trello/docs/format-text-in-trello/
- Trello — carte modèle réutilisable : https://support.atlassian.com/trello/docs/create-a-template-card/
- Atlassian — Definition of Done : https://www.atlassian.com/agile/project-management/definition-of-done

## Outils qualité & CI

Mis en place à l'itération 7 (ils objectivent les critères « qualité de code » et « démarche DevOps »).

- **PHP-CS-Fixer** — en place. Config `.php-cs-fixer.dist.php` : `@PSR12` + `@Symfony`, `setRiskyAllowed(false)`, finder sur `src/` + `tests/`, plus 4 surcharges maison assumées (`php_unit_method_casing` = `snake_case`, `concat_space` = `one`, `yoda_style` = `false`, alignement des `=>` conservé via `binary_operator_spaces`). Commande : `docker compose exec app vendor/bin/php-cs-fixer fix` (ajouter `--dry-run --diff` pour vérifier sans modifier). — https://cs.symfony.com/
- **PHPStan** — en place. Config `phpstan.dist.neon` : **niveau 8**, paths `src/` + `tests/`, **AUCUNE baseline**, extensions `phpstan-symfony` (containerXmlPath) + `phpstan-doctrine` (objectManagerLoader) + `phpstan-phpunit`. Commande : `docker compose exec app vendor/bin/phpstan analyse`. — https://phpstan.org/
- **GitHub Actions** — en place. `.github/workflows/ci.yml` : **3 jobs** (`cs-fixer`, `phpstan`, `phpunit`) sur `push` et `pull_request` des branches `develop` / `preprod` / `main`. Le job `phpunit` provisionne un service MySQL 8 (création base de test + migrations + fixtures + `importmap:install` pour le rendu des WebTests) ; `cs-fixer` et `phpstan` tournent sans base. — https://docs.github.com/en/actions

Axe futur, **NON encore en place** (ne pas exécuter de commande le concernant ni l'installer sans le proposer d'abord) :

- **Rector** (modernisation automatisée) — https://getrector.com/

## Fin de tâche — fiche de résolution de problème

Lorsqu'une tâche a impliqué un problème non trivial (bug, choix d'architecture, blocage technique), terminer la réponse par une **Fiche de résolution de problème** en français, prête à coller dans Trello et réutilisable dans le dossier projet CDA. Ne pas la produire pour les tâches triviales.

Format :

### [Titre court] — <scope>
- **Contexte / problème** : ce qui était attendu et ce qui bloquait (symptôme observable, message d'erreur).
- **Diagnostic (cause racine)** : la vraie cause identifiée, pas le symptôme.
- **Solution retenue** : ce qui a été fait, et pourquoi.
- **Alternatives écartées** : les pistes envisagées et la raison de leur rejet.
- **Résultat / vérification** : comment on sait que c'est résolu (test, comportement).
- **Critère CDA touché** : ex. « démarche de résolution de problème », « composants sécurisés », « DevOps »…

## Fin de tâche — génération de la carte Trello

À la fin d'une tâche non triviale, terminer la réponse par le contenu d'une carte Trello en français, prêt à coller. Objectif : alimenter le suivi de projet (compétence CDA « Contribuer à la gestion d'un projet informatique » : planification, suivi, procédures qualité, résolution de problème).

### Référence des étiquettes et colonnes (board CreaSlot)

- **Itération** (une seule) : `Itération 1` … `Itération 9`.
- **Catégorie** (une ou plusieurs) : `Conception`, `Base de données`, `Back-end`, `Front-end`, `Sécurité`, `Tests`, `DevOps / Déploiement`.
- **Difficulté / points** (une seule, suite de Fibonacci) : `1` (trivial) · `2` (simple) · `3` (moyen) · `5` (complexe) · `8` (très complexe / risqué).
- **Colonne** (statut) : `Backlog` → `À faire` → `En cours` → `En test` → `Terminé`.

### Format de carte à produire

**Colonne** : <statut>
**Étiquettes** : <Itération N> · <catégorie(s)> · <difficulté : 1|2|3|5|8>
**Dates** : début <jj mois> → limite <jj mois, HH:MM>

**Titre** : US-X.Y — <intitulé court>

**Description** (Markdown) :
> **En tant qu'**<rôle>, **je souhaite** <besoin>, **afin de** <bénéfice>.
>
> #### Objectif
> <1–2 phrases>
>
> #### Difficultés rencontrées
> - <problème → cause racine → solution retenue → alternative écartée>
>
> #### Critères d'acceptation
> - <conditions observables/testables>

**Definition of Done** (checklist Trello) :
- [ ] Tests PHPUnit verts (aucune deprecation/notice/warning)
- [ ] Logique métier + chemins Voters testés
- [ ] Migration Doctrine incluse si le schéma a changé
- [ ] Conventions respectées (nommage, sécurité, accessibilité, Clean Code)
- [ ] Commit(s) Conventional Commits avec `Refs US-X.Y`
- [ ] <critère(s) spécifique(s) à l'US>

Le bloc **Difficultés rencontrées** est obligatoire dès qu'un obstacle a été rencontré : il constitue la matière de la « démarche structurée de résolution de problème » du dossier. Ne pas générer de carte pour les tâches triviales.

## Référence détaillée

`docs/design-tokens.md` est la source de vérité pour toute génération HTML/CSS/Twig (charte graphique, variables `--cs-*`). `docs/CreaSlot_References_Documentaires.md` regroupe les liens documentaires officiels reliés aux critères CDA. Pour un sujet non couvert ici, se reporter à la documentation officielle de la brique concernée (section ci-dessus).
