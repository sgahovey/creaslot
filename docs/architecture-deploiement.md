# Architecture de déploiement — CreaSlot (US-9.2)

> Livrable de mémoire MSP3 — Concepteur Développeur d'Applications (CDA).
> Pose l'**architecture des deux environnements** (préproduction + production) et les
> **choix** qui la sous-tendent. **Rattachement CDA : CP10** (« Préparer et documenter
> le déploiement d'une application » — conception des environnements).

---

## 1. Objet & périmètre

CreaSlot cible **un seul VPS** hébergeant **deux environnements isolés** — préproduction
et production — derrière un reverse-proxy HTTPS unique, à partir d'une **image construite
une seule fois** (`creaslot:prod`, US-9.1).

**Couvert par US-9.2** : la topologie complète et sa **validation en local** via le
certificat auto-signé de Caddy (`tls internal`) — les deux sites répondent en HTTPS, les
en-têtes de sécurité (dont la CSP) sont posés, la pré-prod est protégée, les bases sont
isolées, les workers consomment la file.

**Renvoyé** (non dupliqué ici) :
- **US-9.3 — déploiement réel sur le VPS** : DNS `*.sslip.io`, certificats **ACME Let's
  Encrypt**, secrets de production, `trusted_proxies`, ouverture des ports 80/443, crons
  (`app:envoyer-rappels-j1`, `app:purger-journal`). Couvre la **CI/CD (CP11)**.
- **US-9.4 — exploitation** : runbook opérationnel, sauvegardes/restauration, supervision
  et journalisation des échecs d'authentification (**OWASP A09**).

---

## 2. Vue d'ensemble

```
                         (HTTPS 8443 -> 443)
   Navigateur ──────────────────► ┌───────────────────────────────┐
                                   │            Caddy              │  TLS (internal | ACME)
                                   │  façade unique, en-têtes,     │  HSTS, Permissions-Policy
                                   │  basic_auth (préprod seule)   │  CSP déléguée à l'app
                                   └───────────────┬───────────────┘
                  file_server (assets)             │  php_fastcgi :9000
              ┌────────────────────────────────────┴───────────────────────┐
              │                                                              │
   /srv/preprod (vol. assets_preprod)                       /srv/prod (vol. assets_prod)
              │                                                              │
   ┌──────────▼──────────┐                                      ┌───────────▼─────────┐
   │   app-preprod        │  APP_PREPROD=true                    │   app-prod          │ APP_PREPROD=false
   │   (creaslot:prod)    │                                      │   (creaslot:prod)   │
   └──────────┬───────────┘                                      └───────────┬─────────┘
   ┌──────────▼──────────┐  messenger:consume async             ┌───────────▼─────────┐
   │ worker-preprod       │  (--time-limit=3600                  │ worker-prod         │
   │ (creaslot:prod)      │   --memory-limit=128M)               │ (creaslot:prod)     │
   └──────────┬───────────┘                                      └───────────┬─────────┘
              │                                                              │
              └───────────────► ┌─────────────────────────┐ ◄───────────────┘
                                │       MySQL 8.0 (db)     │  1 instance
                                │  creaslot_preprod (user) │  2 bases isolées
                                │  creaslot_prod    (user) │  2 users (privilèges cloisonnés)
                                └─────────────────────────┘
```

Orchestration : `compose.prod.yml` (projet `creaslot_prod`). Six services — `caddy`, `db`,
`app-preprod`, `app-prod`, `worker-preprod`, `worker-prod` — sur le réseau
`creaslot-prod-net`.

---

## 3. Composants & choix

Chaque choix est donné avec sa **raison** et l'**alternative écartée** — c'est le
*pourquoi* qui se défend.

### 3.1 Caddy en façade unique

**Décision** : un seul conteneur `caddy:2-alpine` assure la terminaison TLS, le service des
assets statiques (`file_server`), la délégation PHP (`php_fastcgi`), les en-têtes de
sécurité et le `basic_auth` de la pré-prod (`docker/caddy/Caddyfile`).
**Pourquoi** : un seul point pour TLS + en-têtes + routage des deux sites ; ACME intégré
sans configuration externe ; HTTP/2 natif.
**Alternative écartée** : *Caddy + nginx conservé* — doublonnait la configuration
(en-têtes posés à deux endroits, deux couches à maintenir) sans bénéfice. nginx reste pour
le **développement** (`docker-compose.yml`), non concerné par cette cible.

**Dual-root** — subtilité clé : le `root` de site sert les statiques depuis le volume
d'assets de l'environnement (`/srv/preprod` ou `/srv/prod`), tandis que `php_fastcgi`
fixe son **propre `root` à `/var/www/html/public`** (chemin réel **dans** le conteneur
app) pour `SCRIPT_FILENAME`. **Pourquoi** : un Caddy unique ne peut pas monter deux
volumes au même chemin ; dissocier les deux racines permet deux sites distincts tout en
laissant php-fpm résoudre le script chez lui. **Alternative écartée** : monter le code des
apps dans Caddy (couplage inutile, le code n'a pas à être lisible par le proxy).

### 3.2 Image *build-once* réutilisée pour app et worker

**Décision** : la même image immuable `creaslot:prod` (US-9.1, stage `runtime`, exécution
non-root uid 1000) sert les conteneurs **app** et **worker**, qui ne diffèrent que par leur
`command`.
**Pourquoi** : *build-once / run-anywhere* — un seul artefact testé est promu de preprod à
prod, garantissant l'identité binaire entre les deux. **Alternative écartée** : deux images
distinctes app/worker — surface de build doublée pour un code identique.

### 3.3 Deux environnements sur un VPS

**Décision** : `app-preprod` (`APP_PREPROD=true`, `APP_ENVIRONMENT_LABEL=preprod`) et
`app-prod` (`APP_PREPROD=false`, `APP_ENVIRONMENT_LABEL=prod`) — même `APP_ENV=prod`
Symfony, la distinction preprod/prod portant sur le **flag applicatif** (bandeau orange +
préfixe `[PRÉPROD]`, cf. `base.html.twig`/`twig.yaml`).
**Pourquoi** : la pré-prod doit être un **miroir** de la prod (même env Symfony `prod`,
mêmes optimisations) pour valider avant promotion ; seul l'affichage signale
l'environnement. **Alternative écartée** : un `APP_ENV=preprod` dédié — Symfony chargerait
une configuration différente, donc la pré-prod ne testerait pas la vraie config de prod.
**Isolation Compose** : `name: creaslot_prod` + ports hôte **8443/8081** (le développement
occupe 8000/8080) — un `docker compose down -v` côté prod n'affecte pas l'environnement de
dev, et inversement.

### 3.4 Données : une instance MySQL, deux bases isolées

**Décision** : un seul service `db` (`mysql:8.0`) hébergeant **`creaslot_preprod`** et
**`creaslot_prod`**, chacune avec **son utilisateur** dont les privilèges sont restreints à
sa propre base (`docker/mysql/init-prod.sh`, exécuté au premier démarrage).
**Pourquoi** : suffisant au volume du Cnam Réunion ; un user par base **cloisonne** preprod
et prod (le compromis d'un environnement n'expose pas l'autre) ; le script d'init rejoue
sans intervention manuelle (clôt aussi le manuel décrit en DT-6 côté prod).
**Alternative écartée** : deux conteneurs MySQL — isolation plus forte mais coût mémoire
doublé, non justifié à cette échelle.

### 3.5 Assets servis par Caddy via synchronisation au démarrage

**Décision** : l'`ENTRYPOINT` de l'image (`docker/app/entrypoint.sh`) copie `public/` (assets
compilés bakés au build) vers le volume `assets_<env>` à chaque démarrage, avant de lancer
`php-fpm` ; Caddy lit ce volume.
**Pourquoi** : Caddy sert les statiques sans passer par PHP (rapide), et la **resynchro à
chaque boot** garde le volume aligné sur la dernière image (robuste aux mises à jour).
**Alternative écartée** : volume nommé initialisé une fois — resterait **figé** sur une
ancienne version d'image après mise à jour.

### 3.6 Workers Messenger asynchrones

**Décision** : un conteneur worker par environnement exécute
`php bin/console messenger:consume async -v --time-limit=3600 --memory-limit=128M`
(`restart: unless-stopped`). Le transport `async` est `doctrine://default?auto_setup=0`.
**Pourquoi** : les emails (confirmation, annulation, rappel) partent **hors du cycle
requête** pour ne pas bloquer l'utilisateur sur le SMTP ; `--time-limit`/`--memory-limit`
recyclent le process périodiquement (anti-fuite mémoire des workers longue durée) ;
`auto_setup=0` évite un `CREATE TABLE` réévalué à chaque connexion — la table
`messenger_messages` est provisionnée **au déploiement** par
`php bin/console messenger:setup-transports` (cf. §4).
**Alternative écartée** : envoi synchrone — bloquait la requête sur la latence SMTP ;
worker via `supervisord` dans le conteneur app — mélange des responsabilités, scaling/restart
moins nets qu'un conteneur dédié.

### 3.7 Configuration et secrets

**Décision** : trois familles de fichiers, deux mécanismes d'injection.
- **`.env.preprod` / `.env.prod`** (versionnés, **non-secrets** : `APP_ENV`, `APP_PREPROD`,
  `APP_ENVIRONMENT_LABEL`, `MESSENGER_TRANSPORT_DSN`) → injectés en variables conteneur via
  `env_file:`.
- **`.env.preprod.local` / `.env.prod.local`** (gitignorés, **secrets** : `APP_SECRET`,
  `DATABASE_URL`, `MAILER_DSN`) → gabarits versionnés `*.local.example`.
- **`.env.deploy.local`** (gitignoré, **infra** : `MYSQL_*`, hôtes, `CADDY_TLS`, ports,
  hash `basic_auth`) → passé en **`--env-file`** dédié pour l'interpolation `${...}` de
  Compose ; gabarit `.env.deploy.local.example`.

**Pourquoi le `--env-file` dédié** : par défaut Compose interpole `${...}` depuis le `.env`
du dépôt — qui porte `APP_ENV=dev` — ce qui **polluerait** la configuration. Un fichier
d'infra séparé cloisonne l'interpolation Compose de la configuration Symfony.
**Pourquoi `.env.preprod`/`.env.prod` exclus de l'image** (`.dockerignore`) : ces fichiers
sont consommés par Compose au runtime ; bakés, Symfony chargerait `.env.prod` du disque
dans le conteneur préprod. **Alternative écartée** : tout dans des `.env.local` baker dans
l'image — secrets dans l'artefact, image non promouvable entre environnements.

### 3.8 Sécurité applicative derrière le proxy

- **HTTPS détecté sans `trusted_proxies`** : `php_fastcgi` transmet `HTTPS=on`, donc
  `Request::isSecure()` est vrai et `session.cookie_secure: auto` (défaut Symfony) suffit
  à émettre un cookie sécurisé.
- **Vraie IP client sans `trusted_proxies`** : vérifié au déploiement (US-9.3), voir la note
  ci-dessous.
- **CSP à nonce (OWASP A05)** : posée **par l'application** (`CspResponseListener` +
  `csp_nonce()`), `script-src 'self' 'nonce-…'` strict, sans `unsafe-inline` ni
  `unsafe-eval`. **Pourquoi côté app et non dans Caddy** : le nonce change par requête, donc
  un en-tête **statique** Caddy ne peut pas le porter. Détails : `docs/audit-securite-owasp.md` §3 (A05).
- En-têtes posés par Caddy (snippet `securite`) : HSTS, X-Frame-Options, X-Content-Type-Options,
  Referrer-Policy, X-XSS-Protection, Permissions-Policy, en-tête `Server` masqué.

### trusted_proxies : vérifié non requis

Caddy est le seul proxy de bordure (aucun proxy en amont). Vérifié empiriquement au
premier déploiement (US-9.3) via une sonde temporaire : une requête externe arrive côté
PHP avec REMOTE_ADDR = IP publique réelle du client, et non une adresse du réseau Docker
(172.x). Caddy, en php_fastcgi, renseigne lui-même REMOTE_ADDR ; le NAT Docker (DNAT
iptables) préserve l'IP source pour le trafic externe.

Conséquence : `Request::getClientIp()` est correct sans configurer
`framework.trusted_proxies`. C'est aussi le choix le plus sûr : déclarer un proxy de
confiance là où il n'y en a pas ouvrirait une surface d'usurpation d'IP via X-Forwarded-For.
Le throttling de connexion (A07) et la future journalisation des échecs (A09) reposent
donc sur une IP client fiable.

Condition de réactivation : si un proxy s'intercale un jour devant Caddy (CDN, load
balancer), déclarer `framework.trusted_proxies` avec son sous-réseau et activer la lecture
des en-têtes `X-Forwarded-*` (`trusted_headers`).

---

## 4. Séquence de mise en route

Ordre **logique** (le détail opérationnel relève d'US-9.3/9.4) :

1. `docker compose -f compose.prod.yml --env-file .env.deploy.local up -d --build`
2. **Init MySQL** : au premier démarrage, `init-prod.sh` crée les deux bases + deux users.
3. **Migrations par environnement** :
   `... exec app-preprod php bin/console doctrine:migrations:migrate --no-interaction` (idem `app-prod`).
4. **Provisionnement du transport** :
   `... exec app-preprod php bin/console messenger:setup-transports` (idem `app-prod`) — crée
   `messenger_messages` (car `auto_setup=0`).
5. Les workers consomment alors la file `async` sans erreur.

---

## 5. Validation locale réalisée (tls internal)

Sur la stack `creaslot_prod` (Caddy `CADDY_TLS=internal`, hôtes `preprod.localhost` /
`prod.localhost`) :

- **Deux sites HTTPS** répondent **200** sur `/connexion`.
- **Bandeau / `[PRÉPROD]`** présent en préprod, absent en prod (`cs-env-prod`).
- **`basic_auth` préprod** : 401 sans identifiants, 200 avec, 401 avec mauvais mot de passe ;
  prod publique (200 sans identifiants).
- **Assets** servis par Caddy (`200`, `Cache-Control: …immutable`).
- **En-têtes** : HSTS, Permissions-Policy, X-Frame-Options, etc. ; **CSP** avec
  `script-src 'self' 'nonce-…'`, **nonce identique** entre l'en-tête et les `<script>` ;
  **JSON de l'API sans CSP**.
- **Workers** : `messenger_messages` créée sur les deux bases, workers stables
  (« Consuming messages from transport "async" », 0 erreur SQL).

Preuve pérenne automatisée : `tests/Controller/CspHeaderTest.php` (intégrée à la suite, cf.
`docs/plan-de-tests.md`).

---

## 6. Renvois explicites

| Sujet | Renvoi |
|---|---|
| DNS `*.sslip.io`, **certificats ACME** Let's Encrypt, ports 80/443 | **US-9.3** |
| Secrets de production (`.env.*.local` réels), `trusted_proxies` | **US-9.3** |
| Crons `app:envoyer-rappels-j1` / `app:purger-journal` | **US-9.3** |
| **CI/CD (CP11)** — pipeline de build et de promotion | **US-9.3** |
| Runbook d'exploitation, sauvegardes/restauration | **US-9.4** |
| Supervision + journalisation des échecs de login (**A09**) | **US-9.4** |

---

## 7. Références

| Fichier | Rôle |
|---|---|
| `compose.prod.yml` | Orchestration des 6 services (projet `creaslot_prod`) |
| `docker/caddy/Caddyfile` | Reverse-proxy : 2 sites dual-root, en-têtes, basic_auth, TLS |
| `docker/mysql/init-prod.sh` | Création des 2 bases + 2 users au premier démarrage |
| `docker/app/entrypoint.sh` | Synchronisation `public/` → volume d'assets au boot |
| `Dockerfile` | Image multi-stage `creaslot:prod` (US-9.1) |
| `.env.preprod` / `.env.prod` | Variables Symfony non-secrètes par environnement |
| `.env.*.local.example` / `.env.deploy.local.example` | Gabarits (secrets / infra) |
| `src/EventListener/CspResponseListener.php` | CSP à nonce (A05) |
