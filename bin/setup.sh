#!/usr/bin/env bash
set -euo pipefail

# Raccourci d'installation locale de CreaSlot.
#
# Usage : ./bin/setup.sh
#
# Ce script n'est PAS un substitut a la procedure du README : il enchaine les
# etapes 5 a 9, qui y restent ecrites une a une. Si une etape echoue ici, la
# procedure manuelle reste la reference pour comprendre pourquoi.
#
# Ce qu'il fait, dans cet ordre :
#   1. schema et donnees de demonstration en environnement de developpement ;
#   2. compilation des assets (la configuration Docker ne les sert pas a la
#      volee : sans cette etape, l'interface s'affiche sans style) ;
#   3. creation de la base de test creaslot_test et attribution des droits a
#      l'utilisateur applicatif, qui n'a PAS le droit de la creer lui-meme ;
#   4. schema et donnees en environnement de test ;
#   5. execution de la suite.
#
# Prerequis : les conteneurs doivent deja tourner (etapes 1 a 4 du README,
# jusqu'a `docker compose up -d`). Le script le verifie et s'arrete sinon.
#
# AUCUN secret dans ce script : le mot de passe root MySQL est lu depuis
# l'environnement du conteneur `db` (variable MYSQL_ROOT_PASSWORD), jamais
# depuis l'hote ni passe en clair sur la ligne de commande.

COMPOSE_FILE=${COMPOSE_FILE:-docker-compose.yml}
APP_SERVICE=${APP_SERVICE:-app}
DB_SERVICE=${DB_SERVICE:-db}
TEST_DB=${TEST_DB:-creaslot_test}
APP_DB_USER=${APP_DB_USER:-creaslot}

# Le script vit dans bin/ : se placer a la racine du depot.
cd "$(dirname "$0")/.."

COMPOSE=(docker compose -f "$COMPOSE_FILE")
CONSOLE=("${COMPOSE[@]}" exec -T "$APP_SERVICE" php bin/console)

etape() { printf '\n\033[1m==> %s\033[0m\n' "$1"; }

# 0. Refuser de continuer si la pile n'est pas la : le message d'erreur de
#    docker compose exec est bien moins parlant que celui-ci.
if ! "${COMPOSE[@]}" ps --status running --services 2>/dev/null | grep -qx "$APP_SERVICE"; then
    echo "ERREUR: le service '$APP_SERVICE' ne tourne pas." >&2
    echo "Lancez d'abord : docker compose up -d" >&2
    exit 1
fi

etape "1/5 Schema et donnees de demonstration (developpement)"
"${CONSOLE[@]}" doctrine:migrations:migrate --no-interaction
"${CONSOLE[@]}" doctrine:fixtures:load --no-interaction

etape "2/5 Compilation des assets"
"${CONSOLE[@]}" asset-map:compile

etape "3/5 Base de test et droits"
"${COMPOSE[@]}" exec -T "$DB_SERVICE" sh -c \
    "mysql -u root -p\"\$MYSQL_ROOT_PASSWORD\" -e \"
        CREATE DATABASE IF NOT EXISTS ${TEST_DB} CHARACTER SET utf8mb4;
        GRANT ALL PRIVILEGES ON ${TEST_DB}.* TO '${APP_DB_USER}'@'%';
        FLUSH PRIVILEGES;\""

etape "4/5 Schema et donnees (test)"
"${CONSOLE[@]}" doctrine:migrations:migrate --env=test --no-interaction
"${CONSOLE[@]}" doctrine:fixtures:load --env=test --no-interaction

etape "5/5 Suite de tests"
"${COMPOSE[@]}" exec -T "$APP_SERVICE" php bin/phpunit

printf '\n\033[32mInstallation terminee.\033[0m Application : http://localhost:8000\n'
