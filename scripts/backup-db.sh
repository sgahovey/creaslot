#!/usr/bin/env bash
set -euo pipefail

# Sauvegarde compressee de la base CreaSlot via mysqldump
# (coherent InnoDB grace a --single-transaction).
#
# Usage PROD (defauts) : ./scripts/backup-db.sh
# Usage DEV            : COMPOSE_FILE=docker-compose.yml ENV_FILE= DB_NAME=creaslot ./scripts/backup-db.sh
#
# AUCUN secret dans ce script : le mot de passe root MySQL est lu depuis
# l'environnement du conteneur `db` (variable MYSQL_ROOT_PASSWORD), jamais
# depuis l'hote ni passe en clair sur la ligne de commande.

COMPOSE_FILE=${COMPOSE_FILE:-compose.prod.yml}
ENV_FILE=${ENV_FILE:-.env.deploy.local}
DB_SERVICE=${DB_SERVICE:-db}
DB_NAME=${DB_NAME:-creaslot_prod}
BACKUP_DIR=${BACKUP_DIR:-$HOME/backups/creaslot}
RETENTION_DAYS=${RETENTION_DAYS:-14}

# Le script vit dans scripts/ : se placer a la racine du repo.
cd "$(dirname "$0")/.."

# Prefixe compose dans un tableau ; --env-file seulement s'il est fourni
# (le mode DEV utilise un ENV_FILE vide).
COMPOSE=(docker compose -f "$COMPOSE_FILE")
if [ -n "$ENV_FILE" ]; then
    COMPOSE+=(--env-file "$ENV_FILE")
fi

mkdir -p "$BACKUP_DIR"
chmod 700 "$BACKUP_DIR"

OUT="$BACKUP_DIR/creaslot_${DB_NAME}_$(date +%Y%m%d_%H%M%S).sql.gz"
TMP="$OUT.part"

# Nettoie le fichier partiel en cas d'echec (inoffensif si succes).
trap 'rm -f "$TMP"' EXIT

# Dump atomique : avec set -o pipefail, un echec de mysqldump fait echouer le pipe.
# $MYSQL_ROOT_PASSWORD reste en quotes SIMPLES -> evalue DANS le conteneur.
# $DB_NAME est passe en argument positionnel ($1 du sh -c, "_" tient lieu de $0)
# -> aucune interpolation cote hote, pas de risque d'injection shell.
"${COMPOSE[@]}" exec -T "$DB_SERVICE" \
    sh -c 'exec mysqldump -uroot -p"$MYSQL_ROOT_PASSWORD" --single-transaction --no-tablespaces "$1"' _ "$DB_NAME" \
    | gzip -c > "$TMP"

# Un dump vide signale un echec silencieux : on refuse de le promouvoir.
[ -s "$TMP" ] || { echo "ERREUR: dump vide" >&2; exit 1; }

mv "$TMP" "$OUT"
chmod 600 "$OUT"

# Purge des sauvegardes au-dela de la retention.
find "$BACKUP_DIR" -name 'creaslot_*.sql.gz' -type f -mtime +"$RETENTION_DAYS" -delete

echo "Sauvegarde OK : $OUT"
du -h "$OUT"
