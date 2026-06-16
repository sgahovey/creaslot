#!/bin/sh
# Entrypoint runtime (US-9.2) : synchronise les assets compilés bakés dans l'image
# (public/) vers un volume partagé que Caddy sert. Resync à chaque boot -> robuste
# aux mises à jour d'image (le volume nommé seul resterait figé sur l'ancienne version).
set -e

if [ -d /var/www/html/public ]; then
    mkdir -p /srv-assets
    cp -a /var/www/html/public/. /srv-assets/
fi

exec "$@"
