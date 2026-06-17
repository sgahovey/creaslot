# Configuration cron — Rappels J-1 (US-4.6)

## Objectif

Exécuter automatiquement chaque jour à 18h (heure Réunion) la commande
`app:envoyer-rappels-j1` qui envoie un email de rappel à tous les Auditeurs
ayant un rendez-vous prévu pour le lendemain.

## Vérification que la commande fonctionne

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

```bash
ssh ubuntu@51.178.25.175
crontab -e
```

### Étape 2 — Ajouter la ligne suivante

```cron
# CreaSlot — Rappels J-1 (heure Réunion = UTC+4, pas de DST ; VPS en UTC)
# Exécution : tous les jours à 14h00 UTC = 18h00 heure Réunion.
0 14 * * * cd /home/ubuntu/creaslot && /usr/bin/docker compose -f compose.prod.yml --env-file .env.deploy.local exec -T app-prod php bin/console app:envoyer-rappels-j1 >> /home/ubuntu/cron-logs/rappels-j1.log 2>&1
```

Notes :

- Chemin **absolu** de `docker` (`/usr/bin/docker`) : le cron a un PATH minimal.
- `exec -T` : pas de TTY en contexte cron.
- L'invocation cible le conteneur applicatif **prod** via `compose.prod.yml` + `--env-file .env.deploy.local`.

### Étape 3 — Vérifier que la cron est bien enregistrée

```bash
crontab -l | grep envoyer-rappels-j1
```

### Étape 4 — Créer le dossier de logs (propriétaire `ubuntu`)

```bash
mkdir -p /home/ubuntu/cron-logs
```

Aucun `sudo`/`chown` nécessaire : `/home/ubuntu/cron-logs` appartient déjà à `ubuntu`.

### Étape 5 — Test post-déploiement

Le lendemain à 18h01 (heure Réunion), vérifier :

```bash
# Vérifier que la commande s'est exécutée
tail -20 /home/ubuntu/cron-logs/rappels-j1.log

# Vérifier en BDD que les rappels sont marqués
cd /home/ubuntu/creaslot && /usr/bin/docker compose -f compose.prod.yml --env-file .env.deploy.local exec -T app-prod \
  php bin/console doctrine:query:sql \
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
