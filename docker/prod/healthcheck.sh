#!/usr/bin/env bash
set -e

# Verify PHP-FPM is available
php -v >/dev/null 2>&1 || exit 1

# Optionally verify Laravel is bootable (non-fatal if artisan missing)
if [ -f artisan ]; then
  php artisan config:clear >/dev/null 2>&1 || true
fi

exit 0