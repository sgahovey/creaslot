# Runbook de déploiement et d'exploitation — CreaSlot (production)

Procédures **opérationnelles** pour déployer et exploiter CreaSlot en production.
Pour les **choix de conception** (pourquoi Caddy en façade, image *build-once*, dual-root,
CSP à nonce, `trusted_proxies`…), voir `docs/architecture-deploiement.md` — ce runbook
ne contient que des procédures et des commandes copiables.

**Périmètre** : déploiement, mise à jour, certificats, e-mail, crons, sauvegarde et
restauration de la base (§8), rollback simple.
La supervision/monitoring applicatif est en place (six sondes Uptime Kuma + route `/health`,
cf. §3.1 et §10) ; seule l'extension des healthchecks Docker à l'ensemble des services
(aujourd'hui `db`) reste ouverte. La journalisation des échecs de connexion (OWASP A09) est
livrée (US-9.5, cf. §10).

## 1. Accès et environnement
- VPS OVH Ubuntu, IP **51.178.25.175**, fuseau **`Etc/UTC`**.
- Connexion : `ssh ubuntu@51.178.25.175` (clé ed25519 ; authentification par mot de passe et login `root` désactivés).
- Projet : `~/creaslot`.
- Pare-feu `ufw` : ports **22, 80, 443** ouverts.

## 2. Fichiers de configuration
- `compose.prod.yml` — services : `db`, `app-preprod`, `app-prod`, `worker-preprod`, `worker-prod`. Le reverse-proxy Caddy **ne fait plus partie de cette stack** : depuis le découplage (PR #117), il vit dans un dépôt d'infrastructure dédié `infra-proxy` (cf. §4 et `docs/architecture-deploiement.md`).
- `.env.deploy.local` (**secret**, infra ; passé via `--env-file`) : hosts, `CADDY_TLS` (e-mail ACME), `CADDY_ACME_CA` (vide = prod), ports, `MYSQL_*`, `PREPROD_BASICAUTH_*` (hash bcrypt **échappé `$$`**).
- `.env.prod.local` / `.env.preprod.local` (**secrets**, app) : `APP_SECRET`, `DATABASE_URL`, `MAILER_DSN`.
- Gabarits `*.example` versionnés ; les `*.local` sont **gitignorés** (jamais commités).
- **Préfixe commun de toutes les commandes** :
  ```bash
  docker compose -f compose.prod.yml --env-file .env.deploy.local <...>
  ```

### 2.1 TLS applicatif vers MySQL

Le serveur MySQL sait faire du TLS depuis son premier démarrage : `have_ssl = YES`, et les
certificats auto-signés ont été générés dans le datadir le **16/06/2026**. Ce qui manquait était
côté client : PDO n'ouvre pas de session chiffrée sans qu'on le lui demande.

**Ce qui a été mis en place**

1. L'autorité `ca.pem` a été extraite du conteneur `db` et **versionnée** dans
   `docker/mysql/ca.pem`. C'est une clé publique, pas un secret.
2. Elle est montée en lecture seule sur `/etc/mysql/ca.pem` dans les **quatre** services
   applicatifs, `app-preprod`, `app-prod`, `worker-preprod` et `worker-prod`. Les workers
   sont concernés au même titre : leur transport Messenger est `doctrine://default`, donc
   la même connexion.
3. `config/packages/doctrine.yaml` pose `MYSQL_ATTR_SSL_CA` et
   `MYSQL_ATTR_SSL_VERIFY_SERVER_CERT = false` dans le bloc **`when@prod` uniquement**.
   La vérification du nom est désactivée parce que le certificat auto-signé porte le CN
   `MySQL_Server_8.0.46_Auto_Generated_CA_Certificate`, qui ne correspond pas à l'hôte `db` ;
   le chiffrement du transport, lui, reste effectif.

> ⚠️ **Ne jamais écrire ces options dans le bloc `dbal` commun** : l'intégration continue
> monte son propre service MySQL sans ce certificat, et les trois jobs qui touchent la base
> échoueraient.

**Vérification** (la session doit être chiffrée, pas seulement autorisée) :

```bash
$PFX exec -T app-prod php bin/console dbal:run-sql \
  "SELECT variable_name, variable_value FROM performance_schema.session_status \
   WHERE variable_name IN ('Ssl_cipher','Ssl_version')"
```

Deux valeurs vides signifient session en clair. Une suite du type `TLS_AES_256_GCM_SHA384`
signifie que le chiffrement est actif.

**Retour arrière**, en une ligne : retirer le bloc `options` de `when@prod` dans
`doctrine.yaml`, redéployer, et les connexions repassent en clair. Aucune action serveur n'est
nécessaire : `require_secure_transport` reste à `OFF` et aucun compte ne porte de `REQUIRE SSL`.
C'est délibéré, et cela doit le rester : poser `REQUIRE SSL` sur un compte est le seul geste
de ce chantier qui ne se défait pas sans intervention en base.

> ⚠️ **Réserve importante, l'autorité est liée au volume de données.** Un `down -v` sur la
> stack de production détruirait `mysql_data_prod`, et MySQL régénérerait au démarrage suivant
> une **nouvelle** autorité. Le `ca.pem` versionné deviendrait alors obsolète et **plus aucune
> connexion applicative ne s'établirait**, puisque la vérification de la chaîne échouerait.
> Dans ce cas, réextraire l'autorité et la recommiter :
> `$PFX cp db:/var/lib/mysql/ca.pem docker/mysql/ca.pem`. Ce scénario transforme une remise à
> zéro anodine en panne bloquante : à garder en tête avant tout `down -v` sur ce serveur.

## 3. Mise à jour de code (procédure courante)

### 3.1 Procédure nominale (pipeline CI/CD via Pull Request)

En exploitation normale, on ne se connecte pas au VPS : on **promeut une branche par Pull Request**
et le pipeline GitHub Actions déploie (détail : `docs/architecture-deploiement.md` §5).

> **Branches permanentes** : `develop`, `preprod` et `main` ne se suppriment JAMAIS après un merge.
> Ignorer le message GitHub « branch can be safely deleted », qui ne concerne que les branches de feature.

**Pourquoi une PR et pas un push direct** : les branches `preprod` et `main` sont protégées par un
ruleset qui **refuse le push direct** et impose une **Pull Request mergée en squash**
(`allowed_merge_methods: ["squash"]`, 4 contrôles requis au vert). La promotion se fait donc par PR.
L'ancienne méthode `git merge --ff-only` + `git push origin <branche>` est désormais **bloquée** par le ruleset.

> **Le squash ne casse PAS la cohérence du SHA** : chaque workflow de déploiement **reconstruit
> l'image au SHA du push** (`build-push.yml` est appelé par le job de deploy). Le tag d'image et le
> SHA déployé valent tous deux `github.sha` du push, donc build et deploy sont toujours cohérents,
> et le code déployé est identique à celui de la branche source. Aucun besoin d'un SHA identique
> d'un environnement à l'autre.

**Préproduction (déploiement automatique)** :

1. Créer la PR de `develop` vers `preprod` :
   ```bash
   gh pr create --base preprod --head develop --title "deploy: promotion develop vers preprod"
   ```
2. Attendre les **4 contrôles verts** (PHP-CS-Fixer, PHPStan, PHPUnit, `composer audit`) **et SonarCloud**.
3. Merger en squash :
   ```bash
   gh pr merge <NUM> --squash
   ```

Le merge pousse sur `preprod` et déclenche le workflow *Deploiement preprod* (build de l'image au
SHA, puis deploy SSH). Smoke test attendu **401** (la préprod est protégée par le basic_auth Caddy,
qui répond 401 avant l'application). Aucune action manuelle sur le VPS.

**Production (déploiement après approbation manuelle)** :

1. Créer la PR de `preprod` vers `main` :
   ```bash
   gh pr create --base main --head preprod --title "deploy: mise en production"
   ```
2. Attendre les **4 contrôles verts**.
3. Merger en squash :
   ```bash
   gh pr merge <NUM> --squash
   ```

Le merge pousse sur `main` et déclenche *Deploiement prod*. Le job `build` construit l'image, puis
le job `deploy` **se met en pause** sur l'approbation manuelle (`environment: production`). Approuver
via **Actions → run « Deploiement prod » → Review deployments → cocher `production` → Approve and
deploy**. Après approbation : deploy SSH, puis smoke test attendu **200** (la prod n'a pas de
basic_auth). On ne promeut vers `main` qu'une fois la préprod validée (*promote-on-green*).

**Après le déploiement prod** :

1. Vérifier que le site répond :
   ```bash
   curl -s -o /dev/null -w "%{http_code}\n" https://creaslot.re/connexion   # attendu 200
   curl -s -o /dev/null -w "%{http_code}\n" https://creaslot.re/health       # attendu 200
   ```
2. Créer le tag de version et la release GitHub (depuis `main` à jour). Remplacer `vX.Y.Z` par le numero de version reel (ex. `v1.1.0`) :
   ```bash
   git checkout main && git pull --ff-only
   git tag vX.Y.Z
   git push origin vX.Y.Z
   gh release create vX.Y.Z --title "CreaSlot vX.Y.Z" --notes "Notes de version."
   ```

### 3.2 Procédure de secours — déploiement manuel sur le VPS

À n'utiliser que si le pipeline est indisponible (incident GitHub Actions, urgence)
ou pour un correctif appliqué directement sur le VPS.

> ⚠️ **IMPORTANT** — L'image **embarque le code** et tourne avec OPcache
> `validate_timestamps=0`. Un `git pull` seul **ne prend JAMAIS effet** → il **FAUT
> rebuild + recreate**.

```bash
cd ~/creaslot
git pull --ff-only origin <branche>
# Build mono-service (un seul service porte le build:, les 3 autres réutilisent l'image)
docker compose -f compose.prod.yml --env-file .env.deploy.local build app-prod
# Recrée app-* et worker-* avec la nouvelle image
docker compose -f compose.prod.yml --env-file .env.deploy.local up -d
# Si nouvelle migration :
docker compose -f compose.prod.yml --env-file .env.deploy.local exec app-prod    php bin/console doctrine:migrations:migrate --no-interaction
docker compose -f compose.prod.yml --env-file .env.deploy.local exec app-preprod php bin/console doctrine:migrations:migrate --no-interaction
# Si nouveau transport Messenger :
docker compose -f compose.prod.yml --env-file .env.deploy.local exec app-prod php bin/console messenger:setup-transports
# Smoke :
curl -s -o /dev/null -w "%{http_code}\n" https://creaslot.re/connexion         # attendu 200
curl -s -o /dev/null -w "%{http_code}\n" https://preprod.creaslot.re/connexion  # attendu 401
```

> ⚠️ **Pré-requis migration `Version20260629120000` (trigger + procédure, US-12.1)** —
> Avant de promouvoir une version contenant cette migration vers **préprod/prod**,
> s'assurer que le service MySQL a le paramètre **`log_bin_trust_function_creators=1`**
> (`command: --log-bin-trust-function-creators=1` du service `db` dans `compose.prod.yml`,
> comme en DEV). Sans ce paramètre, la migration échoue avec l'**erreur 1419** (création
> d'un trigger sans privilège `SUPER` alors que le binary logging est actif). Cela vaut
> pour le déploiement nominal (§3.1, migration jouée par le pipeline) comme manuel (§3.2).

### 3.3 Commande compose ciblée sur un seul service : exporter le tag d'image

> ⚠️ **Toute commande `docker compose` qui résout une image (`pull`, `up`, `up --force-recreate`)
> lancée à la main sur un service précis DOIT être précédée de l'export du tag.** Sinon la
> commande échoue sur :
>
> ```
> Error failed to resolve reference "ghcr.io/sgahovey/creaslot:latest": not found
> ```

**Cause.** `compose.prod.yml` déclare `image: ghcr.io/sgahovey/creaslot:${PREPROD_IMAGE_TAG:-latest}`
(idem `PROD_IMAGE_TAG` pour la prod). Le pipeline exporte cette variable avant d'appeler compose
(`scripts/deploy-ci.sh`, §3.1) ; un shell interactif, lui, ne l'a pas, et compose retombe sur le
`latest` par défaut. Or le registre GHCR ne contient **que des tags SHA** : `latest` n'y est jamais
poussé, la résolution échoue donc toujours.

**Retrouver le bon tag.** Interroger le conteneur en place, service par service :

```bash
docker inspect creaslot_prod-app-prod-1 --format '{{.Config.Image}}'
```

Ne PAS se fier à `git rev-parse HEAD` dans `~/creaslot` : `deploy-ci.sh` fait un `git reset --hard`
sur le commit déployé, quel que soit l'environnement. Après un déploiement de préproduction, le HEAD
du dépôt désigne donc la version de **préprod**, pas celle de production. Les deux environnements
tournent couramment sur des tags différents, c'est le principe même du *promote-on-green*.

**Forme correcte.**

```bash
cd ~/creaslot
export PROD_IMAGE_TAG=<SHA 40 caractères relevé ci-dessus>
docker compose -f compose.prod.yml --env-file .env.deploy.local up -d --force-recreate app-prod
```

La procédure de secours §3.2 échappe à ce piège tant que son `build` est bien exécuté avant le
`up -d` : l'image est alors produite localement. Si l'on saute l'étape de build, la même règle
s'applique.

## 4. HTTPS / certificats (Caddy dans le dépôt `infra-proxy`)
- Le reverse-proxy Caddy vit dans le dépôt d'infrastructure dédié **`infra-proxy`** (découplé de la stack CreaSlot en PR #117 ; cf. `docs/architecture-deploiement.md`). **Toutes les opérations sur le proxy — certificats, hosts, CA ACME, `basic_auth` — s'effectuent dans ce dépôt**, pas via `compose.prod.yml`.
- Caddy **obtient et renouvelle** les certificats automatiquement (ACME). Domaines : `creaslot.re` (prod, apex) et `preprod.creaslot.re` ; enregistrements DNS **A** pointant vers `51.178.25.175`.
- `CADDY_ACME_CA` **vide = CA PRODUCTION**. Pour tester sans griller le rate-limit Let's Encrypt :
  ```
  CADDY_ACME_CA=https://acme-staging-v02.api.letsencrypt.org/directory
  ```
  (certificats **non reconnus** par les navigateurs, c'est normal en staging).
- Après modification d'un host ou de la CA : recréer le proxy **depuis le dépôt `infra-proxy`** (selon sa procédure propre), et non via `compose.prod.yml`.
- La **préprod** est protégée par `basic_auth` (`PREPROD_BASICAUTH_USER` / `PREPROD_BASICAUTH_HASH`), configuré dans `infra-proxy`.

## 5. E-mail transactionnel (Brevo)
- Domaine `creaslot.re` **authentifié chez Brevo** via 4 entrées DNS dans la zone OVH : code Brevo (`TXT @`), DKIM `brevo1._domainkey` + `brevo2._domainkey` (`CNAME`), DMARC (`_dmarc`, `TXT`).
- `MAILER_DSN=brevo+api://<cle-api>@default` dans `.env.prod.local` (**PROD**). Préprod : `MAILER_DSN=null://null` (aucun envoi réel).
- Expéditeur : `noreply@creaslot.re` (`APP_NOTIFICATION_FROM`). Envoi **asynchrone** via le worker (`messenger:consume`).
- Test d'envoi :
  ```bash
  docker compose -f compose.prod.yml --env-file .env.deploy.local exec app-prod php bin/console app:email:test <destinataire-que-vous-consultez>
  ```

## 6. Tâches planifiées (crons)
- Crontab de l'utilisateur `ubuntu`, **3 entrées** (VPS en UTC) :
  - Sauvegarde de la base : `30 2 * * *` (quotidienne, 02h30 UTC).
  - Rappels J-1 : `0 14 * * *` (= 18h00 heure Réunion).
  - Purge du journal RGPD : `0 3 1 * *` (1er du mois, 03h00 UTC).
- Logs : `~/cron-logs/backup-db.log`, `~/cron-logs/rappels-j1.log` et `~/cron-logs/purger-journal.log`.
- Lignes exactes et procédures détaillées : `docs/cron-backup.md`, `docs/cron-rappels-j1.md` et `docs/cron-purger-journal.md`.

### 6.1 Alerte de sécurité poussée (blocage après plafonnement, OWASP A09)

Le canal de journalisation `security` conserve la trace d'un blocage, mais personne ne lit un
fichier de journal en continu. Un moniteur Uptime Kuma dédié transforme cette trace en
notification Discord immédiate.

**Principe.** Le moniteur est de type **push**, en **mode inversé** (*Upside Down Mode*). Un
moniteur push signale normalement l'ABSENCE de battement ; le mode inversé retourne cette
logique, de sorte que l'absence de sollicitation vaut situation normale et la sollicitation vaut
incident. L'application ne pousse donc que lorsqu'un blocage survient, et le moniteur se réarme
tout seul une fois l'intervalle écoulé sans nouvelle sollicitation.

**Configuration du moniteur, à créer dans l'interface** (`https://status.creaslot.re`, un moniteur
par environnement) :

| Champ | Valeur préproduction | Valeur production |
|---|---|---|
| Monitor Type | Push | Push |
| Friendly Name | `CreaSlot : Blocages de connexion (preprod)` | `CreaSlot : Blocages de connexion (prod)` |
| Heartbeat Interval | `300` | `300` |
| Retries | `0` | `0` |
| Resend Notification if Down X times | `0` | `0` |
| Advanced : Upside Down Mode | **coché** | **coché** |
| Notifications | `Discord CreaSlot` | `Discord CreaSlot` |

Le champ *Push URL* affiché par Kuma contient le jeton du moniteur. Seul ce jeton est reporté dans
la configuration de l'application, jamais l'URL complète.

**Câblage côté application.** Deux variables, séparées selon leur sensibilité :

| Variable | Fichier | Versionné | Rôle |
|---|---|---|---|
| `SUPERVISION_URL_BASE` | `.env.preprod` / `.env.prod` | oui | Adresse interne de Kuma, `http://uptime-kuma:3001` |
| `SUPERVISION_JETON_BLOCAGE_CONNEXION` | `.env.preprod.local` / `.env.prod.local` | **non** | Jeton du moniteur |

L'appel emprunte le réseau Docker interne : il ne sort jamais sur l'internet public, et le jeton ne
transite donc pas par le proxy. **Jeton vide ou absent : aucun appel n'est tenté**, l'écriture du
journal restant assurée. C'est l'état normal en développement, en test et dans l'intégration
continue.

**Ce qui part vers Kuma.** Le message dit qu'un blocage a eu lieu et sur quel environnement, jamais
qui en est l'objet. L'adresse tentée reste dans le journal applicatif. Kuma est un tiers du point de
vue des données, il n'a pas à connaître d'adresse.

**Provoquer un blocage pour tester.** Six tentatives de connexion avec un mot de passe erroné sur la
même adresse, `login_throttling` étant réglé à cinq (`config/packages/security.yaml`) :

```bash
for i in $(seq 1 6); do
  curl -s -c /tmp/cookies -b /tmp/cookies https://preprod.creaslot.re/connexion > /tmp/page.html
  jeton=$(grep -o 'name="_csrf_token" value="[^"]*"' /tmp/page.html | cut -d'"' -f4)
  curl -s -o /dev/null -c /tmp/cookies -b /tmp/cookies -X POST https://preprod.creaslot.re/connexion \
    -d "email=<adresse-de-test>&password=motdepasse-errone&_csrf_token=$jeton"
done
```

La sixième tentative produit la ligne `Connexion bloquée après plafonnement des tentatives` dans
`var/log/security-<date>.log` et déclenche la notification Discord.

**Si l'alerte n'arrive pas.** Le journal fait foi : la présence de la ligne prouve que le blocage a
bien eu lieu. Chercher ensuite dans le même fichier `Alerte de supervision injoignable` ou
`Alerte de supervision refusée`, qui tracent l'échec de l'appel sortant sans jamais divulguer le
jeton. Une supervision en panne ne peut pas empêcher une connexion d'aboutir, c'est une propriété
couverte par les tests.

## 7. Administration courante
```bash
# Créer un super-administrateur (interactif, mot de passe masqué)
docker compose -f compose.prod.yml --env-file .env.deploy.local exec app-prod php bin/console app:creer-admin

# État des services
docker compose -f compose.prod.yml --env-file .env.deploy.local ps

# Logs d'un service (app-prod, worker-prod, db…) — le proxy Caddy se journalise dans le dépôt infra-proxy
docker compose -f compose.prod.yml --env-file .env.deploy.local logs <service> --tail 50
```

## 8. Sauvegarde et restauration de la base

### Sauvegarde
- Script versionné : `scripts/backup-db.sh`, **automatisé par cron** (quotidien, 02h30 UTC — cf. `docs/cron-backup.md`). Lancement **manuel** possible à tout moment depuis le VPS :
```bash
  cd ~/creaslot && ./scripts/backup-db.sh
```
- Produit un dump **compressé et horodaté** dans `~/backups/creaslot/`
  (`creaslot_creaslot_prod_AAAAMMJJ_HHMMSS.sql.gz`), en `chmod 600` (données nominatives → accès restreint).
- `mysqldump --single-transaction` : dump cohérent des tables InnoDB sans verrou bloquant. Le mot de passe
  n'apparaît jamais en clair : il est lu depuis l'environnement du conteneur `db`.
- **Rétention** : à chaque exécution, les dumps de plus de **14 jours** sont purgés (variable `RETENTION_DAYS`).
- Variante (variables surchargeables) — ex. base de préproduction : `DB_NAME=creaslot_preprod ./scripts/backup-db.sh`.

### Restauration
Toujours restaurer d'abord dans une **base jetable** pour vérifier le dump sans risque ; vers la production
uniquement en cas d'incident réel. Préfixe commun :
```bash
PFX="docker compose -f compose.prod.yml --env-file .env.deploy.local"
DUMP=~/backups/creaslot/creaslot_creaslot_prod_AAAAMMJJ_HHMMSS.sql.gz   # choisir le dump voulu
```
1. **Vérification dans une base jetable** (ne touche pas la prod) :
```bash
   $PFX exec -T db sh -c 'mysql -uroot -p"$MYSQL_ROOT_PASSWORD" -e "DROP DATABASE IF EXISTS creaslot_restore_test; CREATE DATABASE creaslot_restore_test"'
   zcat "$DUMP" | $PFX exec -T db sh -c 'mysql -uroot -p"$MYSQL_ROOT_PASSWORD" creaslot_restore_test'
```
   Contrôle d'intégrité (comparer à la source) :
```bash
   $PFX exec -T db sh -c 'mysql -uroot -p"$MYSQL_ROOT_PASSWORD" -N -e "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=\"creaslot_restore_test\""'
   $PFX exec -T db sh -c 'mysql -uroot -p"$MYSQL_ROOT_PASSWORD" -N -e "SELECT COUNT(*) FROM creaslot_restore_test.utilisateur"'
```
   Nettoyage : `$PFX exec -T db sh -c 'mysql -uroot -p"$MYSQL_ROOT_PASSWORD" -e "DROP DATABASE IF EXISTS creaslot_restore_test"'`
2. **Restauration réelle vers la production** (⚠️ écrase les données actuelles — uniquement en cas d'incident) :
```bash
   zcat "$DUMP" | $PFX exec -T db sh -c 'mysql -uroot -p"$MYSQL_ROOT_PASSWORD" creaslot_prod'
```
   Vérifier ensuite l'application ; au besoin recréer les conteneurs applicatifs : `$PFX up -d`.

> Procédure de restauration **testée le 17/06/2026** sur le VPS (dump réel restauré dans une base jetable,
> intégrité vérifiée : 10/10 tables, comptes de lignes conformes). Un backup non testé n'est pas un backup.

### Limites et évolutions
- **Automatisation par cron** : en place (sauvegarde quotidienne à 02h30 UTC — cf. `docs/cron-backup.md`).
  Le lancement manuel reste possible.
- Dumps stockés **localement sur le VPS** : **point unique de défaillance** (perte du serveur = perte des
  sauvegardes). Limite assumée à ce stade.
- **Évolution encore ouverte** : copie **hors-VPS** (`scp` avant une échéance importante, ou stockage objet
  chiffré) pour lever ce point unique de défaillance.

## 9. Rollback simple
```bash
cd ~/creaslot
git checkout <commit-stable>
docker compose -f compose.prod.yml --env-file .env.deploy.local build app-prod
docker compose -f compose.prod.yml --env-file .env.deploy.local up -d
```
Le pipeline CI/CD *build-once / promote-on-green* est en place (US-10.1, cf. §3.1) ; ce rollback manuel reste une procédure de secours où le build se fait **sur le VPS**.

## 10. Pistes d'évolution

Les chantiers initialement listés ici ont été livrés :

- **US-9.5** — logs Docker bornés (`max-size`/`max-file`) et journalisation dédiée des échecs de connexion (channel Monolog `security`, OWASP A09).
- **US-10.1** — pipeline CI/CD de déploiement continu (cf. §3.1 et `docs/architecture-deploiement.md` §5).
- **Supervision applicative** — dispositif Uptime Kuma à **six sondes** : trois interrogations directes (santé applicative via `/health`, préproduction, production publique) et trois sondes en attente de signal (sauvegarde de la base, rappels J-1, purge du journal). La route `/health` (état app + base + file Messenger) est aussi interrogée par le contrôle de disponibilité du §3.1.

Restent ouvertes, par ordre de priorité :

- **Copie hors-VPS des sauvegardes** : la sauvegarde quotidienne par cron est en place (cf. `docs/cron-backup.md`) ; reste à externaliser une copie chiffrée (`scp` ou stockage objet) pour lever le point unique de défaillance.
- **Extension des healthchecks Docker** à l'ensemble des services (aujourd'hui limités à `db`).
