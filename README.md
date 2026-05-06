# CreaSlot

Application web de gestion de rendez-vous pour le Cnam Réunion.  
Développée dans le cadre du mémoire MSP3 — Titre Concepteur Développeur d'Applications (Bac+4 alternance).

Permet aux Auditeurs (étudiants) de réserver des créneaux proposés par le Personnel administratif.
Trois types de RDV : présentiel, téléphone, visio.

> Documentation complète du projet : `docs/` (architecture, maquettes, API)

---

## Stack technique

| Composant | Technologie |
|---|---|
| Langage | PHP 8.4 |
| Framework | Symfony 8 |
| ORM | Doctrine |
| Base de données | MySQL 8 |
| Templates | Twig |
| Front | Bootstrap 5 |
| Conteneurisation | Docker + Docker Compose |
| Déploiement | Railway (preprod + prod) |
| CI/CD | GitHub Actions |
| Qualité code | SonarCloud |
| Emails | Brevo via Symfony Mailer |
| Monitoring | Uptime Kuma + Dozzle |
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

### 4. Vérifier que tout fonctionne

```bash
docker compose ps
```

Les trois services (`app`, `nginx`, `db`) doivent afficher `healthy`.

Ouvrir [http://localhost:8000](http://localhost:8000) dans un navigateur.

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

### Symfony (disponible après US-1.2)

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

### Tests (disponible après US-6.x)

```bash
docker compose exec app php bin/phpunit
docker compose exec app php bin/phpunit --coverage-html var/coverage
```

---

## URLs d'accès

| Environnement | URL |
|---|---|
| Développement local | http://localhost:8000 |
| Pré-production (Railway) | https://preprod.creaslot.re |
| Production (Railway) | https://creaslot.re |

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

---

## Stratégie de branches

| Branche | Rôle |
|---|---|
| `main` | Livraison finale (fin de projet uniquement) |
| `preprod` | Pré-production déployée sur Railway |
| `develop` | Intégration quotidienne |
| `devops` | Expérimentations infrastructure |
| `feature/US-X.Y-*` | Développement d'une user story |

Workflow : `feature/*` → `develop` → `preprod` → `main`

---

## Licence

Projet académique — Cnam Réunion, 2025-2026.
