#!/usr/bin/env bash
set -euo pipefail

# Script de deploiement invoque par le pipeline CI/CD via SSH (forced command).
# La cle SSH de deploiement est restreinte a CE script dans authorized_keys du VPS :
#   command="/home/ubuntu/creaslot/scripts/deploy-ci.sh",no-pty,... <cle publique>
# Le pipeline appelle : ssh deploy@vps "<env> <tag>"
#   -> $SSH_ORIGINAL_COMMAND = "<env> <tag>"  (aucune autre commande n'est possible : pas de shell).

export PATH="/usr/local/bin:/usr/bin:/bin:$PATH"

# 1. Lire et VALIDER l'entree (env + tag) depuis SSH_ORIGINAL_COMMAND.
read -r ENV TAG _ <<< "${SSH_ORIGINAL_COMMAND:-}"

case "$ENV" in
  preprod|prod) ;;
  *) echo "ERREUR: environnement invalide ('$ENV'). Attendu: preprod | prod." >&2; exit 1 ;;
esac

if ! [[ "$TAG" =~ ^[0-9a-f]{7,40}$ ]]; then
  echo "ERREUR: tag invalide ('$TAG'). Attendu: SHA hexadecimal (7-40 car.)." >&2
  exit 1
fi

# 2. Contexte projet.
cd /home/ubuntu/creaslot
PFX=(docker compose -f compose.prod.yml --env-file .env.deploy.local)

# 3. Variable de tag + cible de smoke selon l'environnement.
if [ "$ENV" = "preprod" ]; then
  export PREPROD_IMAGE_TAG="$TAG"
  SMOKE_URL="https://preprod.creaslot.re/connexion"; SMOKE_CODE="401"
else
  export PROD_IMAGE_TAG="$TAG"
  SMOKE_URL="https://creaslot.re/connexion"; SMOKE_CODE="200"
fi
APP="app-$ENV"; WORKER="worker-$ENV"

echo ">>> Deploiement $ENV @ $TAG"

# 4. Tirer l'image GHCR (tag precis) et recreer UNIQUEMENT les services de cet env.
"${PFX[@]}" pull "$APP" "$WORKER"
"${PFX[@]}" up -d "$APP" "$WORKER"

# 5. Migrations Doctrine (idempotent).
"${PFX[@]}" exec -T "$APP" php bin/console doctrine:migrations:migrate --no-interaction

# 6. Smoke test.
code=$(curl -s -o /dev/null -w "%{http_code}" "$SMOKE_URL" || true)
if [ "$code" != "$SMOKE_CODE" ]; then
  echo "ERREUR: smoke $ENV ($SMOKE_URL -> $code, attendu $SMOKE_CODE)." >&2
  exit 1
fi

echo ">>> OK: $ENV deploye en $TAG (smoke $code)."
