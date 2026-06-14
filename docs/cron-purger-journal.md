# Configuration cron — Purge du journal d'administration (DT-15)

## Objectif

Appliquer la durée de conservation RGPD (**limitation de la conservation**, art. 5.1.e)
du journal d'accountability `journal_admin` (US-5.5) : supprimer périodiquement les
entrées plus anciennes que la durée de conservation (**12 mois** par défaut, cf.
`App\Entity\JournalAdmin::DUREE_CONSERVATION_MOIS`) via la commande
`app:purger-journal`.

La purge est **bornée par la seule date** (`dateAction < seuil`) : on n'efface que ce
qui a dépassé la durée annoncée, jamais une entrée choisie — le caractère
*append-only* du journal est préservé.

## Options de la commande

```bash
docker compose exec -T app php bin/console app:purger-journal [--mois=N] [--dry-run]
```

- `--mois=N` : durée de conservation en mois (défaut : 12). Les entrées antérieures à
  `maintenant − N mois` sont purgées. Valeur refusée si `< 1` (garde-fou contre une
  purge trop large par erreur → code de sortie `INVALID`).
- `--dry-run` : compte les entrées qui *seraient* purgées **sans rien supprimer**.

## Vérification que la commande fonctionne

```bash
# Affiche les détails de la commande
docker compose exec -T app php bin/console list app | grep purger-journal

# Smoke test SANS suppression (recommandé avant la 1re vraie purge)
docker compose exec -T app php bin/console app:purger-journal --dry-run
```

Sortie attendue (cas BDD sans entrée expirée) :

```
Purge du journal d'administration
=================================

 [OK] 0 entrées SERAIENT purgées (antérieures au JJ/MM/AAAA).
```

## Configuration cron Linux (PROD — VPS-2 OVH)

### Étape 1 — Éditer la crontab du user `creaslot`

```bash
ssh creaslot@vps-creaslot.ovh
crontab -e
```

### Étape 2 — Ajouter la ligne suivante

```cron
# CreaSlot — Purge du journal RGPD (rétention 12 mois — DT-15)
# Exécution : le 1er de chaque mois à 03h00.
# Note : le seuil de rétention est calculé EN INTERNE par la commande en heure
# Réunion (Indian/Reunion). Pour une purge mensuelle, l'heure exacte du cron
# n'est donc pas critique — un décalage UTC/Réunion de 4h est sans incidence
# sur une borne exprimée en mois.

0 3 1 * * cd /var/www/creaslot && docker compose exec -T app php bin/console app:purger-journal >> /var/log/creaslot/cron-purge-journal.log 2>&1
```

ℹ️ **Conversion timezone (pour mémoire, non critique ici)** :

- Si TZ serveur = UTC : `0 3 1 * *` s'exécute à 03h00 UTC = 07h00 Réunion.
- Si TZ serveur = Indian/Reunion : `0 3 1 * *` s'exécute à 03h00 Réunion.

Contrairement au rappel J-1 (où l'heure compte), la borne de rétention étant
mensuelle, l'un ou l'autre convient. Vérification timezone serveur :

```bash
date
timedatectl
```

### Étape 3 — Vérifier que la cron est bien enregistrée

```bash
crontab -l | grep purger-journal
```

### Étape 4 — Dossier de logs (mutualisé avec les autres crons CreaSlot)

```bash
sudo mkdir -p /var/log/creaslot
sudo chown creaslot:creaslot /var/log/creaslot
sudo chmod 755 /var/log/creaslot
```

### Étape 5 — Test post-déploiement

Le 1er du mois suivant à 03h05, vérifier :

```bash
# Vérifier que la commande s'est exécutée
tail -20 /var/log/creaslot/cron-purge-journal.log

# Vérifier en BDD qu'il ne reste aucune entrée antérieure à 12 mois
docker compose exec -T app php bin/console doctrine:query:sql \
  "SELECT COUNT(*) AS expirees FROM journal_admin WHERE date_action < (NOW() - INTERVAL 12 MONTH)"
```

## Comportement attendu

### Cas nominal

1. 03h00 (1er du mois) : cron démarre.
2. Commande : calcule le seuil `maintenant − 12 mois` (Indian/Reunion).
3. `JournalAdminRepository::purgerAvant($seuil)` : `DELETE` DQL paramétré, borné par la date.
4. Logs : `[OK] N entrées purgées (antérieures au JJ/MM/AAAA).`

### Mode dry-run (audit)

`app:purger-journal --dry-run` appelle `compterAvant($seuil)` et **ne supprime rien** :

```
 [OK] N entrées SERAIENT purgées (antérieures au JJ/MM/AAAA).
```

À lancer avant la première purge réelle pour valider le volume concerné.

### Traçabilité

Chaque exécution (dry-run ou réelle) émet un log Monolog `info` avec le mode, le
nombre d'entrées et la date seuil (ISO). La purge **ne s'auto-journalise pas** dans
`journal_admin` (le journal reste réservé aux actions d'administration sur les comptes).

## Périmètre — maintenant vs déploiement

- **Maintenant (cette PR, DT-15)** : commande `app:purger-journal` + repository
  (`purgerAvant` / `compterAvant`) + constante de rétention + tests + cette doc.
- **Déploiement (itération 9)** : **l'ajout de la ligne crontab ci-dessus sur le VPS
  est renvoyé au déploiement**, comme le cron des rappels J-1. Aucune planification
  n'est activée à ce stade.

## Backup plan — Si cron Linux indisponible

Symfony Scheduler pourrait planifier la commande comme fallback (worker Messenger,
auto-géré par Symfony) — non installé à ce jour, hors-scope. Cf. note identique dans
`docs/cron-rappels-j1.md`.
