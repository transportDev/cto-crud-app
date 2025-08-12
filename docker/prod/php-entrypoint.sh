#!/usr/bin/env bash
set -euo pipefail

# Ensure storage/cache exist and are writable (useful even with external volume)
mkdir -p storage bootstrap/cache || true
chown -R www-data:www-data storage bootstrap/cache || true
chmod -R ug+rwX storage bootstrap/cache || true

# Best-effort optimization caches
if [ -f artisan ]; then
  php artisan config:cache || true
  php artisan route:cache || true
  php artisan view:cache || true
fi

# Optional migrations/seed toggled via environment at deploy time
if [ "${DO_MIGRATIONS:-false}" = "true" ] && [ -f artisan ]; then
  php artisan migrate --force || exit 1
  if [ "${SEED_ADMIN:-false}" = "true" ]; then
    php artisan db:seed --class=AdminUserSeeder --no-interaction || true
  fi
fi

exec "$@"