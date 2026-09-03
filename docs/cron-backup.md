# Configuration cron — Sauvegarde quotidienne de la base (US-9.4)

## Objectif

Automatiser la sauvegarde de la base de production `creaslot_prod` par une tâche
planifiée quotidienne, sur le VPS, via le script versionné `scripts/backup-db.sh`.
La sauvegarde produit un dump **cohérent** (InnoDB, `mysqldump --single-transaction`),
**compressé** et **horodaté**, et applique seule une **rétention** des anciens dumps.

La procédure de **restauration** (et sa vérification en base jetable) est décrite dans
`docs/runbook-deploiement.md` (§8).

## Le script

```bash
# PROD (défauts) :
cd ~/creaslot && ./scripts/backup-db.sh

# Variante (variables surchargeables) — ex. base de préproduction :
DB_NAME=creaslot_preprod ./scripts/backup-db.sh
```

Comportement (`scripts/backup-db.sh`) :

- `mysqldump --single-transaction` : dump cohérent des tables InnoDB **sans verrou
  bloquant** (aucune interruption de service pendant la sauvegarde).
- **Aucun secret en clair** : le mot de passe root MySQL est lu depuis l'environnement
  du conteneur `db`, jamais passé sur la ligne de commande.
- Écriture d'abord dans un fichier `.part`, **promu seulement après succès** ; un dump
  **vide** est refusé (`[ -s "$TMP" ]`) ; le fichier partiel est nettoyé sur échec (`trap`).
- Défauts prod : `COMPOSE_FILE=compose.prod.yml`, `ENV_FILE=.env.deploy.local`,
  `DB_SERVICE=db`, `DB_NAME=creaslot_prod`, `BACKUP_DIR=$HOME/backups/creaslot`,
  `RETENTION_DAYS=14`.

## Stockage et rétention

- Répertoire : `~/backups/creaslot/` (créé en `chmod 700`).
- Fichiers : `creaslot_creaslot_prod_AAAAMMJJ_HHMMSS.sql.gz`, en **`chmod 600`**
  (données nominatives → accès restreint).
- **Rétention : 14 jours** (variable `RETENTION_DAYS`). À chaque exécution, les dumps de
  plus de 14 jours sont purgés (`find … -mtime +14 -delete`). La purge n'ayant lieu qu'à
  l'exécution du script, elle est effective grâce à la planification quotidienne.

## Configuration cron Linux (PROD — VPS OVH 51.178.25.175)

En place au titre de l'**US-9.4** (exploitation). Le VPS est en fuseau **`Etc/UTC`**
(confirmé via `timedatectl`). L'horaire retenu, **02h30 UTC**, correspond à 06h30 à
La Réunion (heure creuse) et est **décalé** du cron de purge du journal (03h00 UTC) pour
éviter tout chevauchement.

### Étape 1 — Éditer la crontab de l'utilisateur `ubuntu`

```bash
ssh ubuntu@51.178.25.175
crontab -e
```

### Étape 2 — Ajouter la ligne suivante

```cron
# CreaSlot — Sauvegarde quotidienne de la base de PROD (rétention 14 j — US-9.4 ; VPS en UTC)
# Exécution : tous les jours à 02h30 UTC (06h30 heure Réunion, heure creuse), décalée
# de la purge mensuelle (03h00). Le script gère seul le dump cohérent (--single-transaction),
# la compression, l'horodatage et la purge des dumps > 14 jours.
30 2 * * * cd /home/ubuntu/creaslot && PATH=/usr/local/bin:/usr/bin:/bin /bin/bash scripts/backup-db.sh >> /home/ubuntu/cron-logs/backup-db.log 2>&1 && curl -fsS -m 10 -o /dev/null "https://status.creaslot.re/api/push/<JETON_PUSH>?status=up&msg=OK"
```

> **Le battement de supervision est indissociable de cette ligne.** Le `&&` fait que
> `curl` n'est appelé **que si la commande précédente sort en succès** : c'est ce qui
> rend la sonde Uptime Kuma significative. Sans lui, la sonde passerait au rouge chaque
> jour alors que la tâche s'exécute, ou pire, resterait au vert si la tâche échouait.
>
> `<JETON_PUSH>` est à remplacer par le jeton du moniteur, lisible dans son champ
> *Push URL* sur `https://status.creaslot.re`. **Il n'est pas écrit ici, ni dans aucun
> fichier versionné** : quiconque le connaît peut pousser un faux battement et éteindre
> l'alerte. Même règle que pour `SUPERVISION_JETON_BLOCAGE_CONNEXION`, cf. runbook §6.1.


Notes :

- `backup-db.sh` est un **script**, pas une commande console : la crontab ne fait que
  `cd` puis le lancer. Le script effectue lui-même le `docker compose … exec -T db …`.
- `PATH=/usr/local/bin:/usr/bin:/bin` en préfixe de la commande : le cron a un PATH
  minimal, et le script invoque `docker` sans chemin absolu — cette assignation garantit
  que `docker` est résolu (il vit dans `/usr/bin`).
- `/bin/bash` explicite comme interpréteur (le script est en Bash, `set -euo pipefail`).
- Aucune variable prod à passer : les défauts du script ciblent déjà `compose.prod.yml`,
  `.env.deploy.local` et `creaslot_prod`.

### Étape 3 — Vérifier que la cron est bien enregistrée

```bash
crontab -l | grep backup-db
```

### Étape 4 — Dossier de logs (propriétaire `ubuntu`, mutualisé avec les autres crons CreaSlot)

```bash
mkdir -p /home/ubuntu/cron-logs
```

Aucun `sudo`/`chown` nécessaire : `/home/ubuntu/cron-logs` appartient déjà à `ubuntu`.

### Étape 5 — Test (avant de dépendre du cron)

```bash
# a) Lancement manuel du script : un dump doit apparaître
cd ~/creaslot && ./scripts/backup-db.sh
ls -lh ~/backups/creaslot/

# b) Test EXACTEMENT comme le cron l'exécutera (attrape les problèmes de PATH/docker)
cd /home/ubuntu/creaslot && PATH=/usr/local/bin:/usr/bin:/bin /bin/bash scripts/backup-db.sh \
  >> /home/ubuntu/cron-logs/backup-db.log 2>&1
tail -20 /home/ubuntu/cron-logs/backup-db.log
```

Sortie attendue : `Sauvegarde OK : …` suivi de la taille du dump. Si `docker: command
not found` apparaît dans le log, c'est un souci de PATH → ajuster le préfixe `PATH=`.

### Étape 6 — Vérification post-déploiement (le lendemain)

```bash
# La sauvegarde de ~02h30 UTC doit figurer dans le log et sur le disque
tail -20 /home/ubuntu/cron-logs/backup-db.log
ls -lt ~/backups/creaslot/ | head
```

Après 15 jours d'exécution, `ls ~/backups/creaslot/` ne doit plus montrer qu'une fenêtre
glissante de 14 sauvegardes (la rétention a purgé les plus anciennes).

## Comportement attendu

### Cas nominal

1. 02h30 UTC (chaque jour) : le cron démarre.
2. `scripts/backup-db.sh` : `mysqldump --single-transaction` → `gzip` → fichier `.part`.
3. Contrôle non vide, puis `mv` vers le fichier définitif horodaté, `chmod 600`.
4. Purge des dumps de plus de 14 jours.
5. Log : `Sauvegarde OK : ~/backups/creaslot/creaslot_creaslot_prod_AAAAMMJJ_HHMMSS.sql.gz`.

### Restauration

Voir `docs/runbook-deploiement.md` (§8) : restauration d'abord dans une **base jetable**
avec contrôle d'intégrité, puis vers la production uniquement en cas d'incident. Procédure
testée le 17/06/2026. *Un backup non testé n'est pas un backup.*

## Limites et évolutions

- Dumps stockés **localement sur le VPS** : **point unique de défaillance** (perte du
  serveur = perte des sauvegardes). Limite assumée à ce stade.
- **Évolution encore ouverte** : copie **hors-VPS** (`scp` avant une échéance importante,
  ou stockage objet chiffré) pour lever ce point unique de défaillance.

## Backup plan — Si cron Linux indisponible

Symfony Scheduler pourrait planifier l'appel comme fallback (worker Messenger, auto-géré
par Symfony) — non installé à ce jour, hors-scope. Note identique dans
`docs/cron-purger-journal.md` et `docs/cron-rappels-j1.md`.
