# Configuration cron — Rappels J-1 (US-4.6)

**À qui s'adresse ce document, et ce qu'il permet.** À la personne qui exploite CreaSlot sur le
serveur, et qui n'a à suivre cette procédure qu'une seule fois. Elle met en place l'envoi
automatique, chaque soir, d'un courriel de rappel aux Auditeurs dont le rendez-vous a lieu le
lendemain. Les termes techniques qui reviennent (`crontab`, *smoke test*, idempotence) sont définis
dans le glossaire, en fin de `docs/runbook-deploiement.md`.

## Objectif

Exécuter automatiquement chaque jour à 18h (heure Réunion) la commande
`app:envoyer-rappels-j1` qui envoie un email de rappel à tous les Auditeurs
ayant un rendez-vous prévu pour le lendemain.

## Vérification que la commande fonctionne

On obtient deux choses : la fiche de la commande, qui prouve qu'elle existe bien dans l'application,
puis son exécution réelle, qui indique combien de rappels ont été envoyés.

```bash
# Affiche les détails de la commande
docker compose exec -T app php bin/console list app | grep envoyer-rappels-j1

# Smoke test exécution (en environnement DEV)
docker compose exec -T app php bin/console app:envoyer-rappels-j1
```

Sortie attendue (cas BDD vide) :

```
Envoi des rappels J-1
=====================

Recherche des réservations ACTIVE pour le JJ/MM/2026 (Réunion)...

 [OK] Rappels J-1 : 0 envoyés, 0 erreurs.
```

## Configuration cron Linux (PROD — VPS OVH 51.178.25.175)

En place depuis **US-9.3** (déploiement réel). Le VPS est en fuseau **`Etc/UTC`**
(confirmé via `timedatectl`) : l'heure Réunion étant UTC+4 sans changement d'heure,
`0 14 * * *` (14h00 UTC) correspond à **18h00 heure Réunion**.

### Étape 1 — Éditer la crontab de l'utilisateur `ubuntu`

On obtient, ouvert dans un éditeur, le carnet des tâches planifiées de la machine, avec son contenu
actuel.

```bash
ssh ubuntu@51.178.25.175
crontab -e
```

### Étape 2 — Ajouter la ligne suivante

Une fois cette ligne enregistrée, les rappels partiront seuls chaque soir à 18h, heure de La
Réunion, la tâche écrira son compte rendu dans un fichier, et signalera à la supervision qu'elle a
bien eu lieu.

```cron
# CreaSlot — Rappels J-1 (heure Réunion = UTC+4, pas de DST ; VPS en UTC)
# Exécution : tous les jours à 14h00 UTC = 18h00 heure Réunion.
0 14 * * * cd /home/ubuntu/creaslot && /usr/bin/docker compose -f compose.prod.yml --env-file .env.deploy.local exec -T app-prod php bin/console app:envoyer-rappels-j1 >> /home/ubuntu/cron-logs/rappels-j1.log 2>&1 && curl -fsS -m 10 -o /dev/null "https://status.creaslot.re/api/push/<JETON_PUSH>?status=up&msg=OK"
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

- Chemin **absolu** de `docker` (`/usr/bin/docker`) : le cron a un PATH minimal.
- `exec -T` : pas de TTY en contexte cron.
- L'invocation cible le conteneur applicatif **prod** via `compose.prod.yml` + `--env-file .env.deploy.local`.

### Étape 3 — Vérifier que la cron est bien enregistrée

On obtient la ligne que l'on vient d'écrire. Si rien ne s'affiche, c'est qu'elle n'a pas été
enregistrée.

```bash
crontab -l | grep envoyer-rappels-j1
```

### Étape 4 — Créer le dossier de logs (propriétaire `ubuntu`)

On obtient le dossier dans lequel la tâche écrira son compte rendu à chaque exécution.

```bash
mkdir -p /home/ubuntu/cron-logs
```

Aucun `sudo`/`chown` nécessaire : `/home/ubuntu/cron-logs` appartient déjà à `ubuntu`.

### Étape 5 — Test post-déploiement

Le lendemain à 18h01 (heure Réunion), vérifier. On obtient d'abord les dernières lignes du compte
rendu, puis les cinq derniers rendez-vous pour lesquels un rappel a été envoyé, avec la date d'envoi.

```bash
# Vérifier que la commande s'est exécutée
tail -20 /home/ubuntu/cron-logs/rappels-j1.log

# Vérifier en BDD que les rappels sont marqués
cd /home/ubuntu/creaslot && /usr/bin/docker compose -f compose.prod.yml --env-file .env.deploy.local exec -T app-prod \
  php bin/console dbal:run-sql \
  "SELECT id, rappel_envoye_at FROM reservation WHERE rappel_envoye_at IS NOT NULL ORDER BY id DESC LIMIT 5"
```

## Comportement attendu

### Cas nominal — RDV prévu demain à 14h00

1. 18h00 (J-1) : cron démarre
2. Commande : `findActivesPourDemainSansRappel(demain_00h, demain_23h59)`
3. Pour chaque réservation :
   - Envoi email rappel via `NotificationService`
   - Marquage `rappelEnvoyeAt = now()` (timezone Réunion)
4. Flush BDD unique en fin de commande
5. Logs : `[OK] Rappels J-1 : N envoyés, M erreurs.`

### Idempotence

La commande peut être relancée sans dommage : aucun Auditeur ne reçoit deux fois le même rappel.

Si le cron est relancé manuellement le même jour :

```bash
docker compose exec -T app php bin/console app:envoyer-rappels-j1
# → [OK] Rappels J-1 : 0 envoyés, 0 erreurs.
```

Car la query Repository filtre `WHERE rappelEnvoyeAt IS NULL` — les réservations déjà rappelées ne sont pas re-traitées.

### Résilience erreur partielle

Si l'envoi échoue pour une réservation (SMTP down, etc.) :

- La commande continue avec la réservation suivante
- L'erreur est loguée via `LoggerInterface::error` avec contexte (`reservation_id`, `exception`, `message`)
- `rappelEnvoyeAt` n'est PAS setté → retry naturel au prochain cron

## Monitoring (futur, hors-scope itération 4)

À envisager pour la production :

- Alerting si `[OK] Rappels J-1 : 0 envoyés, X erreurs.` plusieurs jours d'affilée
- Métriques Prometheus (count rappels envoyés / jour)
- Dashboard Grafana

Hors-scope MSP3, à traiter en itération 6 (déploiement prod) ou après soutenance.

## Backup plan — Si cron Linux indisponible

Symfony Scheduler peut être configuré comme fallback :

- Plus de pièces (worker Messenger)
- Mais auto-géré par Symfony
- Migration : remplacer la commande Console par un Message + Schedule

Hors-scope itération 4.
