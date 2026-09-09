# Configuration cron — Purge du journal d'administration (DT-15)

**À qui s'adresse ce document, et ce qu'il permet.** À la personne qui exploite CreaSlot sur le
serveur, et qui n'a à suivre cette procédure qu'une seule fois. Elle met en place l'effacement
automatique des traces d'administration devenues trop anciennes : le projet s'engage à ne les
conserver que douze mois, et c'est cette tâche planifiée qui tient l'engagement, chaque mois, sans
que personne ait à y penser. Les termes techniques qui reviennent (`crontab`, `dry-run`,
*smoke test*) sont définis dans le glossaire, en fin de `docs/runbook-deploiement.md`.

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

La commande efface les entrées du journal plus anciennes que la durée de conservation. Sans option,
elle applique la durée par défaut ; les deux options permettent de changer cette durée, ou de
regarder ce qui serait effacé sans rien effacer.

```bash
docker compose exec -T app php bin/console app:purger-journal [--mois=N] [--dry-run]
```

- `--mois=N` : durée de conservation en mois (défaut : 12). Les entrées antérieures à
  `maintenant − N mois` sont purgées. Valeur refusée si `< 1` (garde-fou contre une
  purge trop large par erreur → code de sortie `INVALID`).
- `--dry-run` : compte les entrées qui *seraient* purgées **sans rien supprimer**.

## Vérification que la commande fonctionne

On obtient deux choses : la fiche de la commande, qui prouve qu'elle existe bien dans l'application,
puis le nombre d'entrées qui seraient effacées, sans qu'aucune ne le soit réellement.

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

## Configuration cron Linux (PROD — VPS OVH 51.178.25.175)

En place depuis **US-9.3** (déploiement réel). Le VPS est en fuseau **`Etc/UTC`**
(confirmé via `timedatectl`). La borne de rétention étant mensuelle, l'heure exacte
n'est pas critique : `0 3 1 * *` (03h00 UTC le 1er du mois) convient.

### Étape 1 — Éditer la crontab de l'utilisateur `ubuntu`

On obtient, ouvert dans un éditeur, le carnet des tâches planifiées de la machine, avec son contenu
actuel.

```bash
ssh ubuntu@51.178.25.175
crontab -e
```

### Étape 2 — Ajouter la ligne suivante

Une fois cette ligne enregistrée, la purge s'exécutera seule le 1er de chaque mois, écrira son
compte rendu dans un fichier, et signalera à la supervision qu'elle a bien eu lieu.

```cron
# CreaSlot — Purge du journal RGPD (rétention 12 mois — DT-15 ; VPS en UTC)
# Exécution : le 1er de chaque mois à 03h00 UTC. Le seuil de rétention est calculé
# EN INTERNE par la commande en heure Réunion ; pour une borne mensuelle, le décalage
# UTC/Réunion de 4h est sans incidence.
0 3 1 * * cd /home/ubuntu/creaslot && /usr/bin/docker compose -f compose.prod.yml --env-file .env.deploy.local exec -T app-prod php bin/console app:purger-journal >> /home/ubuntu/cron-logs/purger-journal.log 2>&1 && curl -fsS -m 10 -o /dev/null "https://status.creaslot.re/api/push/<JETON_PUSH>?status=up&msg=OK"
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
crontab -l | grep purger-journal
```

### Étape 4 — Dossier de logs (propriétaire `ubuntu`, mutualisé avec les autres crons CreaSlot)

On obtient le dossier dans lequel la tâche écrira son compte rendu à chaque exécution.

```bash
mkdir -p /home/ubuntu/cron-logs
```

Aucun `sudo`/`chown` nécessaire : `/home/ubuntu/cron-logs` appartient déjà à `ubuntu`.

### Étape 5 — Test post-déploiement

Le 1er du mois suivant à 03h05, vérifier. On obtient d'abord les dernières lignes du compte rendu,
puis un nombre : **zéro** signifie qu'il ne subsiste aucune entrée plus ancienne que douze mois, donc
que la purge a bien fait son travail.

```bash
# Vérifier que la commande s'est exécutée
tail -20 /home/ubuntu/cron-logs/purger-journal.log

# Vérifier en BDD qu'il ne reste aucune entrée antérieure à 12 mois
cd /home/ubuntu/creaslot && /usr/bin/docker compose -f compose.prod.yml --env-file .env.deploy.local exec -T app-prod \
  php bin/console dbal:run-sql \
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

- **DT-15 (couche applicative)** : commande `app:purger-journal` + repository
  (`purgerAvant` / `compterAvant`) + constante de rétention + tests + cette doc.
- **Déploiement (US-9.3)** : **la ligne crontab ci-dessus est en place sur le VPS**,
  comme le cron des rappels J-1.

## Backup plan — Si cron Linux indisponible

Symfony Scheduler pourrait planifier la commande comme fallback (worker Messenger,
auto-géré par Symfony) — non installé à ce jour, hors-scope. Cf. note identique dans
`docs/cron-rappels-j1.md`.
