# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Projet

CreaSlot — application web de gestion de rendez-vous pour le Cnam Réunion (mémoire MSP3, Concepteur Développeur d'Applications). Les Auditeurs (étudiants) réservent des créneaux proposés par le Personnel administratif. Trois types de RDV : présentiel, téléphone, visio.

Stack : PHP 8.4 / Symfony 8 (pas 7.4 LTS — choix assumé) / Doctrine ORM / MySQL 8 / Twig / Bootstrap 5. Pas de Redis, pas de NoSQL — sur-ingénierie pour le besoin. Tout passe par Docker.

## Commandes

Tout s'exécute dans le conteneur `app` via `docker compose exec`. La conversation a lieu sous Windows (PowerShell) mais les commandes Docker sont identiques.

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

### Modèle de données (5 entités, src/Entity/)

- `Utilisateur` (table `utilisateur`) — implémente `UserInterface` ; champ `role` (enum `RoleUtilisateur`), pas d'héritage Doctrine. Mot de passe hashé en argon2id (`mot_de_passe_hash`).
- `Service` — rattachement organisationnel du Personnel.
- `TypeRdv` — présentiel / visio / téléphone, avec `couleur_hex` stockée en BDD et lue dynamiquement par Twig.
- `Creneau` — appartient à un Utilisateur (Personnel), caractérisé par un TypeRdv.
- `Reservation` — un Auditeur réserve un Creneau ; statut enum `StatutReservation` (ACTIVE / ANNULEE).

Conventions BDD : tables `snake_case` singulier, foreign keys toujours `id_<entité>` (`id_utilisateur`, `id_creneau`), clés primaires `BIGINT UNSIGNED AUTO_INCREMENT`.

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
- AssetMapper + Stimulus + Turbo (pas de bundler externe).
- **Charte graphique** : `docs/design-tokens.md` est la source de vérité unique. Utiliser les variables CSS `--cs-*` plutôt que des codes hex en dur ; préfixer les classes custom par `cs-`.

## Conventions de code

- `declare(strict_types=1);` en tête de chaque fichier PHP.
- Attributs PHP 8 partout (`#[ORM\Entity]`, `#[Route]`, `#[IsGranted]`) — jamais d'annotations YAML.
- Injection par constructor (`readonly`, promoted properties).
- Enums PHP 8.1+ plutôt que constantes de classe.
- Repositories étendent `ServiceEntityRepository`.
- Préférer les exceptions métier custom (`OverlappingSlotException`, `ConflictingReservationException`) aux codes retour ou null.
- Noms en français côté métier : `creneau`, `reservation`, `auditeur`, `personnel`, `detecteChevauchements()`. Pas d'abréviations (`$u`, `$r`).

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

## Référence détaillée

`.cursorrules` (1100+ lignes) contient les conventions exhaustives : Clean Code, sécurité OWASP, accessibilité RGAA, logging Monolog, exemples de commits, DoD des PR. Le consulter avant de générer du code sur un sujet non couvert ici. `docs/design-tokens.md` pour toute génération HTML/CSS/Twig.
