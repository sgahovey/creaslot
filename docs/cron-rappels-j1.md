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

## Configuration cron Linux (PROD — VPS-2 OVH)

### Étape 1 — Éditer la crontab du user `creaslot`

```bash
ssh creaslot@vps-creaslot.ovh
crontab -e
```

### Étape 2 — Ajouter la ligne suivante

```cron
# CreaSlot — Rappels J-1 (heure Réunion = UTC+4, pas de DST)
# Exécution : tous les jours à 18h00 heure Réunion
# Note : le serveur OVH étant en UTC, on schedule à 14h00 UTC pour atteindre 18h00 Réunion.
# Si le serveur est configuré en TZ=Indian/Reunion, schedule à 18h00 directement.

0 14 * * * cd /var/www/creaslot && docker compose exec -T app php bin/console app:envoyer-rappels-j1 >> /var/log/creaslot/cron.log 2>&1
```

⚠️ **Conversion timezone à confirmer côté VPS** :

- Si TZ serveur = UTC : `0 14 * * *` (= 18h Réunion)
- Si TZ serveur = Indian/Reunion : `0 18 * * *`

Vérification timezone serveur :

```bash
date
timedatectl
```

### Étape 3 — Vérifier que la cron est bien enregistrée

```bash
crontab -l | grep envoyer-rappels-j1
```

### Étape 4 — Créer le dossier de logs

```bash
sudo mkdir -p /var/log/creaslot
sudo chown creaslot:creaslot /var/log/creaslot
sudo chmod 755 /var/log/creaslot
```

### Étape 5 — Test post-déploiement

Le lendemain à 18h01 (heure Réunion), vérifier :

```bash
# Vérifier que la commande s'est exécutée
tail -20 /var/log/creaslot/cron.log

# Vérifier en BDD que les rappels sont marqués
docker compose exec -T app php bin/console doctrine:query:sql \
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
