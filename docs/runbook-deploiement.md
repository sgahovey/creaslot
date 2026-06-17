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

> ⚠️ **IMPORTANT** — L'image **embarque le code** et tourne avec OPcache `validate_timestamps=0`.
> Un `git pull` seul **ne prend JAMAIS effet** → il **FAUT rebuild + recreate**.

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

## 8. Rollback simple
```bash
cd ~/creaslot
git checkout <commit-stable>
docker compose -f compose.prod.yml --env-file .env.deploy.local build app-prod
docker compose -f compose.prod.yml --env-file .env.deploy.local up -d
```
Le pipeline CI/CD *build-once / promote-on-green* fera l'objet d'une US dédiée ; ici le build se fait **sur le VPS**.

## 9. À venir (US-9.4)
- Sauvegardes BDD automatisées.
- Supervision / monitoring.
- Journalisation des échecs de connexion (OWASP A09).
