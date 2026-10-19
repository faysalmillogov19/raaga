#!/usr/bin/env bash
# Déploiement de Raaga en production (APP_ENV=production). Le moindre échec arrête le déploiement (set -e) ; après le
# passage en maintenance, « php artisan up » est toujours exécuté, même en cas d'erreur (trap).
#
# Variables facultatives : RAAGA_BACKUP_DIR (défaut /data/backups), RAAGA_PHP_FPM (service php-fpm, défaut php8.4-fpm).
set -euo pipefail

cd "$(dirname "$0")"

BACKUP_DIR="${RAAGA_BACKUP_DIR:-/data/backups}"
PHP_FPM="${RAAGA_PHP_FPM:-php8.4-fpm}"

# Lecture d'une variable du .env (guillemets retirés).
env_val() { grep -E "^$1=" .env | tail -n 1 | cut -d= -f2- | sed -e 's/^"//' -e 's/"$//'; }

if [ "$(env_val APP_ENV)" != "production" ]; then
    echo "deploy.sh est réservé à APP_ENV=production." >&2
    exit 1
fi

# 1. Code
# Fichiers de travail du poste, ignorés par git donc jamais déployés : docs/maquettes/ (maquettes de référence),
# docs/INVENTAIRE-*.md (inventaires locaux), docs/video/, docs/captures/. Par précaution, retirés s'ils existaient.
git pull --ff-only
rm -rf docs/maquettes docs/INVENTAIRE-FRONT-OFFICE.md docs/INVENTAIRE-BACK-OFFICE.md

# 2. Dépendances PHP
composer install --no-dev --optimize-autoloader --no-interaction

# Assets : fichiers statiques versionnés dans public/ (css, js, vendor), sans étape de compilation.

# 3. Contrôle de production : seuls les points bloquants arrêtent le déploiement (démo, paiement simulé).
php artisan config:clear
php artisan raaga:verifier-production

# 4. Sauvegarde de la base avant les migrations
mkdir -p "$BACKUP_DIR"
SAUVEGARDE="$BACKUP_DIR/raaga-$(date +%Y%m%d-%H%M%S).dump"
PGPASSWORD="$(env_val DB_PASSWORD)" pg_dump --format=custom --no-owner \
    --host="$(env_val DB_HOST)" --port="$(env_val DB_PORT)" --username="$(env_val DB_USERNAME)" \
    --file="$SAUVEGARDE" "$(env_val DB_DATABASE)"
echo "Sauvegarde : $SAUVEGARDE"

# 5. Maintenance (toujours levée ensuite, même en cas d'erreur), migrations
php artisan down --retry=60
trap 'php artisan up' EXIT

php artisan migrate --force

# 6. Référentiels uniquement (idempotents : ce qui manque est créé, rien n'est modifié ni supprimé)
php artisan db:seed --class=ReferentielsSeeder --force

# 7. Lien de stockage et caches
if [ ! -L public/storage ]; then
    php artisan storage:link
fi
php artisan config:cache
php artisan route:cache
php artisan view:cache

# 8. Files d'attente et PHP-FPM
php artisan queue:restart
sudo systemctl reload "$PHP_FPM"

# 9. Remise en ligne (le trap la refait sans effet si tout s'est bien passé)
php artisan up
