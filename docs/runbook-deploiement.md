# Runbook de déploiement et d'exploitation — CreaSlot (production)

Procédures **opérationnelles** pour déployer et exploiter CreaSlot en production.
Pour les **choix de conception** (pourquoi Caddy en façade, image *build-once*, dual-root,
CSP à nonce, `trusted_proxies`…), voir `docs/architecture-deploiement.md` — ce runbook
ne contient que des procédures et des commandes copiables.

**Périmètre** : déploiement, mise à jour, certificats, e-mail, crons, rollback simple.
Hors périmètre (→ **US-9.4**) : sauvegardes BDD, supervision/monitoring, journalisation
des échecs de connexion (OWASP A09).

## 1. Accès et environnement
- VPS OVH Ubuntu, IP **51.178.25.175**, fuseau **`Etc/UTC`**.
- Connexion : `ssh ubuntu@51.178.25.175` (clé ed25519 ; authentification par mot de passe et login `root` désactivés).
- Projet : `~/creaslot`.
- Pare-feu `ufw` : ports **22, 80, 443** ouverts.

## 2. Fichiers de configuration
- `compose.prod.yml` — services : `caddy`, `db`, `app-preprod`, `app-prod`, `worker-preprod`, `worker-prod`.
- `.env.deploy.local` (**secret**, infra ; passé via `--env-file`) : hosts, `CADDY_TLS` (e-mail ACME), `CADDY_ACME_CA` (vide = prod), ports, `MYSQL_*`, `PREPROD_BASICAUTH_*` (hash bcrypt **échappé `$$`**).
- `.env.prod.local` / `.env.preprod.local` (**secrets**, app) : `APP_SECRET`, `DATABASE_URL`, `MAILER_DSN`.
- Gabarits `*.example` versionnés ; les `*.local` sont **gitignorés** (jamais commités).
- **Préfixe commun de toutes les commandes** :
  ```bash
  docker compose -f compose.prod.yml --env-file .env.deploy.local <...>
  ```

## 3. Mise à jour de code (procédure courante)

### 3.1 Procédure nominale — pipeline CI/CD

En exploitation normale, on ne se connecte pas au VPS : on **promeut une branche**
et le pipeline GitHub Actions déploie (détail : `docs/architecture-deploiement.md` §5).

**Préproduction** — déploiement automatique :

```bash
git checkout preprod && git pull --ff-only
git merge --ff-only develop
git push origin preprod
```

Le push déclenche le workflow *Deploiement preprod* (build de l'image au SHA puis
déploiement) ; smoke attendu **401**. Aucune action manuelle sur le VPS.

**Production** — déploiement après approbation manuelle :

```bash
git checkout main && git pull --ff-only
git merge --ff-only preprod
git push origin main
```

Le push déclenche *Deploiement prod*, qui se met en pause sur *Review deployments*.
La mise en production part après **Actions → run concerné → Review deployments →
Approve and deploy** ; smoke attendu **200**. On ne promeut vers `main` qu'une fois
la préprod validée (*promote-on-green*).

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

## 4. HTTPS / certificats (Caddy + Let's Encrypt)
- Caddy **obtient et renouvelle** les certificats automatiquement (ACME). Domaines : `creaslot.re` (prod, apex) et `preprod.creaslot.re` ; enregistrements DNS **A** pointant vers `51.178.25.175`.
- `CADDY_ACME_CA` **vide = CA PRODUCTION**. Pour tester sans griller le rate-limit Let's Encrypt :
  ```
  CADDY_ACME_CA=https://acme-staging-v02.api.letsencrypt.org/directory
  ```
  (certificats **non reconnus** par les navigateurs, c'est normal en staging).
- Après modification d'un host ou de la CA : recréer Caddy :
  ```bash
  docker compose -f compose.prod.yml --env-file .env.deploy.local up -d caddy
  ```
- La **préprod** est protégée par `basic_auth` (`PREPROD_BASICAUTH_USER` / `PREPROD_BASICAUTH_HASH`).

## 5. E-mail transactionnel (Brevo)
- Domaine `creaslot.re` **authentifié chez Brevo** via 4 entrées DNS dans la zone OVH : code Brevo (`TXT @`), DKIM `brevo1._domainkey` + `brevo2._domainkey` (`CNAME`), DMARC (`_dmarc`, `TXT`).
- `MAILER_DSN=brevo+api://<cle-api>@default` dans `.env.prod.local` (**PROD**). Préprod : `MAILER_DSN=null://null` (aucun envoi réel).
- Expéditeur : `noreply@creaslot.re` (`APP_NOTIFICATION_FROM`). Envoi **asynchrone** via le worker (`messenger:consume`).
- Test d'envoi :
  ```bash
  docker compose -f compose.prod.yml --env-file .env.deploy.local exec app-prod php bin/console app:email:test <destinataire-que-vous-consultez>
  ```

## 6. Tâches planifiées (crons)
- Crontab de l'utilisateur `ubuntu`, **2 entrées** (VPS en UTC) :
  - Rappels J-1 : `0 14 * * *` (= 18h00 heure Réunion).
  - Purge du journal RGPD : `0 3 1 * *` (1er du mois, 03h00 UTC).
- Logs : `~/cron-logs/rappels-j1.log` et `~/cron-logs/purger-journal.log`.
- Lignes exactes et procédures détaillées : `docs/cron-rappels-j1.md` et `docs/cron-purger-journal.md`.

## 7. Administration courante
```bash
# Créer un super-administrateur (interactif, mot de passe masqué)
docker compose -f compose.prod.yml --env-file .env.deploy.local exec app-prod php bin/console app:creer-admin

# État des services
docker compose -f compose.prod.yml --env-file .env.deploy.local ps

# Logs d'un service (caddy, app-prod, worker-prod, db…)
docker compose -f compose.prod.yml --env-file .env.deploy.local logs <service> --tail 50
```

## 8. Sauvegarde et restauration de la base

### Sauvegarde
- Script versionné : `scripts/backup-db.sh`. Lancement **manuel** depuis le VPS :
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
- Dumps stockés **localement sur le VPS** : **point unique de défaillance** (perte du serveur = perte des
  sauvegardes). Limite assumée à ce stade.
- Évolutions : copie **hors-VPS** (`scp` avant une échéance importante, ou stockage objet chiffré) et
  **automatisation par cron** quotidien — le script est déjà prêt pour cela (une ligne de crontab suffit).

## 9. Rollback simple
```bash
cd ~/creaslot
git checkout <commit-stable>
docker compose -f compose.prod.yml --env-file .env.deploy.local build app-prod
docker compose -f compose.prod.yml --env-file .env.deploy.local up -d
```
Le pipeline CI/CD *build-once / promote-on-green* fera l'objet d'une US dédiée ; ici le build se fait **sur le VPS**.

## 10. Pistes d'évolution

Les chantiers initialement listés ici ont été livrés :

- **US-9.5** — logs Docker bornés (`max-size`/`max-file`) et journalisation dédiée des échecs de connexion (channel Monolog `security`, OWASP A09).
- **US-10.1** — pipeline CI/CD de déploiement continu (cf. §3.1 et `docs/architecture-deploiement.md` §5).

Restent ouvertes, par ordre de priorité :

- **Sauvegarde automatisée** : passer le script manuel `scripts/backup-db.sh` en tâche planifiée (cron), avec politique de rétention et copie hors-VPS.
- **Supervision applicative** : route `/health` (état app + base + file Messenger) et extension des healthchecks à l'ensemble des services (aujourd'hui sur `db`).
