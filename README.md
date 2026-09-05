# CreaSlot

Application web de gestion de rendez-vous pour le Cnam Réunion.  
Développée dans le cadre du mémoire MSP3 — Titre Concepteur Développeur d'Applications (Bac+4 alternance).

Permet aux Auditeurs (étudiants) de réserver des créneaux proposés par le Personnel administratif.
Trois types de RDV : présentiel, téléphone, visio.

> Documentation complète du projet : `docs/` (architecture, conception, réalisation, exploitation)

**État du projet** : en production sur [creaslot.re](https://creaslot.re), version **v1.2.0**.
Suite de tests verte, **390 cas** et **1428 assertions**, couverture **85,0 %** mesurée par SonarCloud.
Cinq contrôles en intégration continue, dont quatre bloquants.

---

## Stack technique

| Composant | Technologie |
|---|---|
| Langage | PHP 8.4 |
| Framework | Symfony 8.1 |
| ORM | Doctrine |
| Base de données | MySQL 8 |
| Templates | Twig |
| Front | Bootstrap 5 |
| Conteneurisation | Docker + Docker Compose |
| Déploiement | VPS OVH (Ubuntu), Docker Compose, préproduction et production |
| CI/CD | GitHub Actions |
| Qualité code | SonarCloud |
| Emails | Brevo via Symfony Mailer |
| Reverse proxy | Caddy, dans le dépôt dédié `infra-proxy` (découplé de cette pile) |
| Monitoring | Uptime Kuma et Dozzle, hébergés sur le VPS hors de ce dépôt |
| Tests | PHPUnit |

---

## Prérequis

- [Docker Desktop](https://www.docker.com/products/docker-desktop/) 4.x ou supérieur
- Git

Vérifier l'installation :

```bash
docker --version
docker compose version
```

---

## Installation

### 1. Cloner le dépôt

```bash
git clone https://github.com/sgahovey/creaslot.git
cd creaslot
```

### 2. Configurer les variables d'environnement locales

```bash
cp .env.example .env.local
```

Editer `.env.local` pour ajuster les mots de passe MySQL et le DSN Brevo si nécessaire.  
Les valeurs par défaut du `.env` fonctionnent sans modification pour le développement local.

### 3. Démarrer l'environnement

```bash
docker compose up -d
```

La première exécution télécharge les images et construit le conteneur PHP (~2-3 minutes).  
Les démarrages suivants prennent moins de 30 secondes.

### 4. Vérifier que les conteneurs tournent

```bash
docker compose ps
```

Quatre services démarrent. `app`, `nginx` et `db` affichent `healthy` ;
`phpmyadmin` affiche seulement `Up`, il ne déclare pas de sonde de santé.

### 5. Créer le schéma et charger les données de démonstration

```bash
docker compose exec app php bin/console doctrine:migrations:migrate --no-interaction
docker compose exec app php bin/console doctrine:fixtures:load --no-interaction
```

### 6. Compiler les assets

```bash
docker compose exec app php bin/console asset-map:compile
```

**Cette étape n'est pas optionnelle** : la configuration Docker ne sert pas les assets à la
volée. Sans elle, l'interface s'affiche sans style.

### 7. Ouvrir l'application

- Application : [http://localhost:8000](http://localhost:8000)
- phpMyAdmin : [http://localhost:8080](http://localhost:8080)

Les comptes de démonstration partagent le mot de passe `Motdepasse123!`. Par exemple
`creaslotdemo+julie@gmail.com` pour un Auditeur, `creaslotdemo+jean@gmail.com` pour le
Personnel, `creaslotdemo+admin@gmail.com` pour l'administration.

### 8. Préparer la base de test

La suite de tests utilise une base séparée, `creaslot_test`, que **l'utilisateur applicatif
n'a pas le droit de créer**. Il faut la créer une fois avec le compte root du conteneur,
puis lui appliquer le schéma et les données :

```bash
docker compose exec db sh -c 'mysql -u root -p"$MYSQL_ROOT_PASSWORD" -e "CREATE DATABASE IF NOT EXISTS creaslot_test CHARACTER SET utf8mb4; GRANT ALL PRIVILEGES ON creaslot_test.* TO '"'"'creaslot'"'"'@'"'"'%'"'"'; FLUSH PRIVILEGES;"'
docker compose exec app php bin/console doctrine:migrations:migrate --env=test --no-interaction
docker compose exec app php bin/console doctrine:fixtures:load --env=test --no-interaction
```

Sans cette étape, la suite échoue en masse : **208 erreurs et 3 échecs**, tous dus au même
refus d'accès à `creaslot_test`.

### 9. Lancer la suite

```bash
docker compose exec app php bin/phpunit
```

Résultat attendu :

```
OK (390 tests, 1428 assertions)
```

> **Cette séquence est vérifiée, pas supposée.** Elle a été rejouée le 05/09/2026 depuis un
> environnement remis à zéro par `docker compose down -v`, jusqu'à la suite verte.

### Raccourci

Les étapes 5 à 9 peuvent être enchaînées par un script, une fois les conteneurs démarrés :

```bash
./bin/setup.sh
```

**C'est un raccourci, pas un substitut.** Les neuf étapes ci-dessus restent la référence :
si le script s'arrête, c'est à elles qu'il faut revenir pour comprendre où. Son en-tête
documente son rôle, ses prérequis et ce qu'il fait. Il refuse de démarrer si les conteneurs
ne tournent pas, et il ne contient aucun secret : le mot de passe root MySQL est lu dans
l'environnement du conteneur `db`.

---

## Commandes utiles

### Gestion des conteneurs

```bash
# Démarrer en arrière-plan
docker compose up -d

# Arrêter les conteneurs (données préservées)
docker compose down

# Arrêter et supprimer les volumes (remet la BDD à zéro)
docker compose down -v

# Reconstruire l'image PHP après modification du Dockerfile
docker compose build app
docker compose up -d
```

### Logs

```bash
# Tous les services
docker compose logs -f

# Service spécifique
docker compose logs -f app
docker compose logs -f nginx
docker compose logs -f db
```

### Accès aux shells

```bash
# Shell dans le conteneur PHP
docker compose exec app sh

# Shell MySQL
docker compose exec db mysql -u creaslot -pcreaslot creaslot
```

### Symfony

```bash
# Console Symfony
docker compose exec app php bin/console

# Migrations
docker compose exec app php bin/console doctrine:migrations:migrate

# Fixtures
docker compose exec app php bin/console doctrine:fixtures:load

# Cache
docker compose exec app php bin/console cache:clear
```

### Tests

```bash
docker compose exec app php bin/phpunit
docker compose exec app php bin/phpunit --coverage-html var/coverage
```

---

## URLs d'accès

| Environnement | URL |
|---|---|
| Développement local | http://localhost:8000 |
| Pré-production (VPS, accès restreint) | https://preprod.creaslot.re |
| Production (VPS) | https://creaslot.re |

---

## Variables d'environnement

| Variable | Description | Exemple |
|---|---|---|
| `APP_ENV` | Environnement Symfony | `dev`, `preprod`, `prod` |
| `APP_SECRET` | Clé secrète (32 caractères) | `openssl rand -hex 16` |
| `APP_ENVIRONMENT_LABEL` | Bandeau visuel | `dev`, `preprod`, `prod` |
| `DATABASE_URL` | DSN Doctrine | `mysql://user:pass@db:3306/creaslot` |
| `MYSQL_DATABASE` | Nom de la base | `creaslot` |
| `MYSQL_USER` | Utilisateur MySQL | `creaslot` |
| `MYSQL_PASSWORD` | Mot de passe MySQL | — |
| `MYSQL_ROOT_PASSWORD` | Mot de passe root | — |
| `MAILER_DSN` | DSN Brevo | `brevo+smtp://APIKEY@default` |
| `APP_NOTIFICATION_FROM` | Expéditeur emails | `noreply@creaslot.re` |

Voir `.env.example` pour la liste complète avec commentaires.

---

## Architecture Docker

```
┌─────────────────────────────────────────┐
│  Réseau : creaslot-net                  │
│                                         │
│  nginx:80  ──FastCGI──►  app:9000       │
│      │                       │          │
│  :8000 (hôte)           pdo_mysql       │
│                               │         │
│                         db:3306         │
│                    (mysql_data volume)  │
└─────────────────────────────────────────┘
```

- **nginx** : sert les assets statiques, délègue le PHP à app via FastCGI
- **app** : PHP-FPM 8.4 Alpine, exécute l'application Symfony
- **db** : MySQL 8, données persistées dans le volume `mysql_data`
- **phpmyadmin** : consultation de la base sur le port 8080, confort de développement

---

## Stratégie de branches

| Branche | Rôle |
|---|---|
| `main` | Livraison finale (fin de projet uniquement) |
| `preprod` | Pré-production déployée sur le VPS |
| `develop` | Intégration quotidienne |
| `feature/US-X.Y-*` | Développement d'une user story |

Workflow : `feature/*` → `develop` → `preprod` → `main`

---

## Documentation

| Document | Ce qu'il porte |
|---|---|
| [Runbook de déploiement](docs/runbook-deploiement.md) | Promotion, déploiement, rollback, incidents |
| [Audit de sécurité OWASP](docs/audit-securite-owasp.md) | Les dix catégories, leur traitement, les limites assumées |
| [Plan de tests](docs/plan-de-tests.md) | Stratégie, matrice de traçabilité, résultats |
| [Registre de dette technique](docs/dette-technique.md) | 47 entrées, chacune avec sa cause et sa résolution |
| [Procédure de nommage](docs/procedure-de-nommage.md) | Conventions de code et leur vérification outillée |

---

## Licence

Projet académique — Cnam Réunion, 2025-2026.
