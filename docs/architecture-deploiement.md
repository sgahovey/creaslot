# Architecture de déploiement — CreaSlot (US-9.2 + US-10.1)

> Livrable de mémoire MSP3 — Concepteur Développeur d'Applications (CDA).
> Pose l'**architecture des deux environnements** (préproduction + production) et les
> **choix** qui la sous-tendent. **Rattachement CDA : CP10** (« Préparer et documenter
> le déploiement d'une application » — conception des environnements) **et CP11** (mise en production — pipeline CI/CD de déploiement continu, cf. §5).

---

## 1. Objet & périmètre

CreaSlot cible **un seul VPS** hébergeant **deux environnements isolés** — préproduction
et production — derrière un reverse-proxy HTTPS unique, à partir d'une **image construite
une seule fois** (`creaslot:prod`, US-9.1).

**Couvert par US-9.2** : la topologie complète et sa **validation en local** via le
certificat auto-signé de Caddy (`tls internal`) — les deux sites répondent en HTTPS, les
en-têtes de sécurité (dont la CSP) sont posés, la pré-prod est protégée, les bases sont
isolées, les workers consomment la file.

**Complété par US-10.1** : le **pipeline CI/CD (CP11)** de déploiement continu (build d'une image versionnée sur GHCR, promotion par environnement, déploiement par SSH avec approbation manuelle avant la production), décrit en §5.

**Renvoyé** (non dupliqué ici) :
- **US-9.3 — déploiement réel sur le VPS** : DNS `creaslot.re` / `preprod.creaslot.re` (après une phase initiale en `*.sslip.io`), certificats **ACME Let's
  Encrypt**, secrets de production, `trusted_proxies`, ouverture des ports 80/443, crons
  (`app:envoyer-rappels-j1`, `app:purger-journal`).
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

Orchestration : `compose.prod.yml` (projet `creaslot_prod`). Cinq services — `db`,
`app-preprod`, `app-prod`, `worker-preprod`, `worker-prod` — sur le réseau interne
`creaslot-prod-net`. Le reverse-proxy Caddy **ne fait plus partie de cette stack** :
depuis le découplage (PR #117), il vit dans un dépôt dédié `infra-proxy` et rejoint les
applications via le réseau Docker externe `web` (cf. §3.1). Les conteneurs `app-preprod`
et `app-prod` sont donc raccordés à **deux réseaux** : `creaslot-prod-net` (interne, accès
à `db`) et `web` (externe, joignable par le proxy sous leur nom de service).

---

## 3. Composants & choix

Chaque choix est donné avec sa **raison** et l'**alternative écartée** — c'est le
*pourquoi* qui se défend.

### 3.1 Caddy en façade mutualisée (dépôt dédié)

**Décision** : un conteneur `caddy:2-alpine` assure la terminaison TLS, le service des
assets statiques (`file_server`), la délégation PHP (`php_fastcgi`), les en-têtes de
sécurité et le `basic_auth` de la pré-prod. Depuis le découplage (PR #117), il **ne fait
plus partie de la stack CreaSlot** : il vit dans un dépôt d'infrastructure dédié
(`infra-proxy`), possède son propre cycle de vie, et sert **sept sites** répartis sur
plusieurs applications indépendantes (dont les deux de CreaSlot).
**Pourquoi** : un seul point pour TLS + en-têtes + routage ; ACME intégré sans
configuration externe ; HTTP/2 natif. Le sortir de la stack applicative permet de
redéployer CreaSlot (`docker compose down/up`) sans interrompre le proxy ni les autres
applications servies.
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

**Transport chiffré (DT-42)** : les quatre connexions applicatives vers `db` circulent en TLS.
L'autorité auto-signée que MySQL génère à son premier démarrage est extraite du datadir,
versionnée dans `docker/mysql/ca.pem` (clé publique, pas un secret) et montée en lecture seule
sur les quatre services. Les options sont posées via `driverOptions` et les constantes PDO
(`PDO::MYSQL_ATTR_SSL_CA`), dans le bloc **`when@prod` uniquement** de `doctrine.yaml`.
**Pourquoi pas le bloc `dbal` commun** : l'intégration continue monte son propre MySQL sans ce
certificat, le job `phpunit` échouerait à se connecter.
**Pourquoi la vérification du nom est désactivée** (`MYSQL_ATTR_SSL_VERIFY_SERVER_CERT` à
`false`) : le certificat porte le CN `MySQL_Server_8.0.46_Auto_Generated_CA_Certificate`, qui ne
correspond pas à l'hôte `db`. Le chiffrement du transport reste effectif, mesuré en `TLSv1.3` /
`TLS_AES_256_GCM_SHA384` sur les quatre services.
**Alternative écartée** : une contrainte `REQUIRE SSL` sur les comptes MySQL, qui rendrait le
retour arrière impossible sans intervention en base. Écarté également, le chiffrement des données
**au repos** : la clé maîtresse résiderait sur la machine même qu'elle est censée protéger.

### 3.5 Assets servis par Caddy via synchronisation au démarrage

**Décision** : l'`ENTRYPOINT` de l'image (`docker/app/entrypoint.sh`) copie `public/` (assets
compilés bakés au build) vers le volume `assets_<env>` à chaque démarrage, avant de lancer
`php-fpm` ; Caddy lit ce volume.
**Pourquoi** : Caddy sert les statiques sans passer par PHP (rapide), et la **resynchro à
chaque boot** garde le volume aligné sur la dernière image (robuste aux mises à jour).
**Alternative écartée** : volume nommé initialisé une fois — resterait **figé** sur une
ancienne version d'image après mise à jour.

**Les cinq volumes nommés de la stack** (`compose.prod.yml`) :

| Volume | Monté sur | Rôle |
|---|---|---|
| `assets_preprod` / `assets_prod` | `/srv-assets` | Statiques resynchronisés à chaque boot, lus par Caddy (cf. ci-dessus) |
| `logs_preprod` / `logs_prod` | `/var/www/html/var/log` | Journaux applicatifs persistants : le canal `security` y écrit un fichier rotatif qui **survit à la recréation du conteneur** (A09, DT-44) |
| `mysql_data_prod` | `/var/lib/mysql` | Datadir MySQL, les deux bases |

Le point de montage `/var/www/html/var/log` existe déjà dans l'image en `app:app`
(`Dockerfile`), le volume hérite donc de ces droits sans intervention, sur le modèle de
`/srv-assets`. **Réserve** : ces volumes ne sont pas sauvegardés, `scripts/backup-db.sh` ne
couvrant que la base.

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
  `APP_ENVIRONMENT_LABEL`, `MESSENGER_TRANSPORT_DSN`, `SUPERVISION_URL_BASE`) → injectés en
  variables conteneur via `env_file:`.
- **`.env.preprod.local` / `.env.prod.local`** (gitignorés, **secrets** : `APP_SECRET`,
  `DATABASE_URL`, `MAILER_DSN`, `SUPERVISION_JETON_BLOCAGE_CONNEXION`) → gabarits versionnés
  `*.local.example`.
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

## 5. Pipeline CI/CD (déploiement continu — US-10.1)

La séquence décrite en §4 reste la mise en route manuelle de référence. En
exploitation courante, les mises à jour applicatives sont déployées par un
pipeline d'intégration et de déploiement continus, déclenché par `git push`,
avec une porte de contrôle humain avant toute mise en production.

### 5.1 Principe : branche = environnement (promote-on-green)

Chaque branche longue est liée à un environnement. Un `push` sur `preprod`
déploie automatiquement la préproduction ; un `push` sur `main` déploie la
production, mais seulement après approbation manuelle. La promotion est
linéaire et ne se fait que sur du vert (CI passée) :

`feature/US-X.Y-* → develop → preprod → main`

On ne promeut vers l'environnement suivant qu'une fois l'environnement courant
validé (*promote-on-green*), ce qui garantit qu'un code arrivant en production
a déjà été éprouvé en préproduction sur la même image.

### 5.2 Construction de l'image versionnée (GHCR)

Le workflow réutilisable `.github/workflows/build-push.yml` (appelé via
`workflow_call`) construit l'image applicative et la pousse sur le registre
public GHCR, taguée au SHA du commit : `ghcr.io/sgahovey/creaslot:<github.sha>`.
L'image est ainsi traçable et immuable (un SHA = une image). Conformément au
choix *build-once* (§3.2), cette même image est réutilisée par les services
`app-*` et `worker-*`. Le cache de build GitHub Actions (`type=gha`) accélère
les reconstructions.

### 5.3 Déploiement par SSH avec forced-command

Les workflows `deploy-preprod.yml` et `deploy-prod.yml` se connectent au VPS en
SSH au moyen d'une clé dédiée, distincte de la clé d'administration. Dans le
fichier `authorized_keys` du VPS, cette clé est contrainte par une
*forced-command* : elle ne peut exécuter **que** `scripts/deploy-ci.sh`, sans
shell interactif (`no-pty`), sans redirection de port ni d'agent
(`no-port-forwarding`, `no-agent-forwarding`, `no-X11-forwarding`).

Le script `deploy-ci.sh` lit la commande transmise dans
`$SSH_ORIGINAL_COMMAND` (de la forme `env tag`), puis **valide strictement**
ses arguments — `env` doit appartenir à `{preprod, prod}` et `tag` respecter
`^[0-9a-f]{7,40}$` — avant d'enchaîner : `pull` de l'image, `up -d` du couple
`app`/`worker` ciblé, exécution des migrations Doctrine `--no-interaction`,
puis smoke test.

Ce modèle « push SSH + forced-command » a été préféré à un agent/runner
installé sur le VPS : la surface d'attaque est minimale (une seule commande
autorisée, arguments filtrés contre l'injection), il n'y a pas de runner
supplémentaire à maintenir et à durcir, et les secrets restent centralisés
côté GitHub.

### 5.4 Porte d'approbation manuelle (production)

L'environnement GitHub `production` est protégé : un relecteur requis est
désigné et les déploiements sont restreints à la branche `main`. Lors d'un
`push` sur `main`, le job `deploy` se met en pause sur « Review deployments » :
aucune mise en production n'a lieu sans validation humaine explicite. La
préproduction, à l'inverse, se déploie sans approbation pour favoriser
l'itération rapide.

### 5.5 Smoke tests différenciés

À l'issue de chaque déploiement, un smoke test HTTP vérifie que
l'environnement répond avant de déclarer le job réussi :

- **préprod** : un code `401` est attendu, car le site est placé derrière le
  `basic_auth` de Caddy ; recevoir `401` prouve que Caddy route bien la requête
  et que la protection d'accès est active ;
- **prod** : un code `200` est attendu, le site étant public.

Un smoke en échec fait échouer le job, fournissant un signal immédiat de
non-disponibilité.

### 5.6 Secrets et configuration

Le pipeline n'utilise que trois secrets de dépôt — `VPS_HOST`, `VPS_USER` et
`VPS_SSH_KEY` (la clé privée de déploiement) ; la clé publique correspondante
est installée dans `authorized_keys` du VPS avec la forced-command. **Aucun
secret applicatif ne transite par le pipeline** : les fichiers `.env.*.local`
demeurent sur le VPS (cohérent avec §3.7), le déploiement ne faisant que
basculer le tag d'image et relancer les conteneurs.

## 6. Validation locale réalisée (tls internal)

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

## 7. Renvois explicites

| Sujet | Renvoi |
|---|---|
| DNS `creaslot.re` / `preprod.creaslot.re` (initialement `*.sslip.io`), **certificats ACME** Let's Encrypt, ports 80/443 | **US-9.3** |
| Secrets de production (`.env.*.local` réels), `trusted_proxies` | **US-9.3** |
| Crons `app:envoyer-rappels-j1` / `app:purger-journal` | **US-9.3** |
| **CI/CD (CP11)** — pipeline de build et de promotion | **§5 (ce document, US-10.1)** |
| Runbook d'exploitation, sauvegardes/restauration | **US-9.4** |
| Supervision + journalisation des échecs de login (**A09**) | **US-9.4** |
| Rétention des journaux de sécurité et alerte poussée sur blocage (**A09**) | **DT-44** |
| Chiffrement du transport applicatif vers MySQL (**A02**) | **DT-42**, cf. §3.4 |

---

## 8. Références

| Fichier | Rôle |
|---|---|
| `compose.prod.yml` | Orchestration des 5 services (projet `creaslot_prod`) ; réseaux `creaslot-prod-net` (interne) et `web` (externe, proxy) |
| `Caddyfile` (dépôt `infra-proxy`) | Reverse-proxy mutualisé : dual-root, en-têtes, basic_auth, TLS ; sert les deux sites CreaSlot parmi sept |
| `docker/mysql/init-prod.sh` | Création des 2 bases + 2 users au premier démarrage |
| `docker/mysql/ca.pem` | Autorité auto-signée de MySQL, montée en lecture seule sur les 4 services applicatifs (TLS, DT-42) |
| `docker/app/entrypoint.sh` | Synchronisation `public/` → volume d'assets au boot |
| `Dockerfile` | Image multi-stage `creaslot:prod` (US-9.1) |
| `.env.preprod` / `.env.prod` | Variables Symfony non-secrètes par environnement |
| `.env.*.local.example` / `.env.deploy.local.example` | Gabarits (secrets / infra) |
| `src/EventListener/CspResponseListener.php` | CSP à nonce (A05) |
| `config/packages/monolog.yaml` | Canal `security` sur deux destinations : `stderr` et fichier rotatif persistant (A09, DT-44) |
| `src/Service/AlerteSecuriteService.php` | Alerte poussée vers la sonde de supervision sur blocage de connexion (A09, DT-44) |
