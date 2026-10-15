#!/usr/bin/env bash
# Déploiement de Raaga en production. Le moindre échec arrête le déploiement (set -e), en particulier
# « php artisan raaga:verifier-production » : comptes ou données de démonstration, paiement simulé, coordonnées des
# comptes de destination manquantes. Le site reste alors sur la version en place.
set -euo pipefail

cd "$(dirname "$0")"

if [ "$(php artisan env --no-ansi | grep -o 'production' || true)" != "production" ]; then
    echo "deploy.sh est réservé à APP_ENV=production." >&2
    exit 1
fi

composer install --no-dev --optimize-autoloader --no-interaction
npm ci
npm run build

php artisan config:clear

# Verrous de production : un échec arrête ici, avant la maintenance et les migrations.
php artisan raaga:verifier-production

php artisan down --retry=60
trap 'php artisan up' EXIT

php artisan migrate --force
php artisan db:seed --force
php artisan config:cache
php artisan route:cache
php artisan view:cache
