#!/usr/bin/env bash
# Famboook Laravel — deployment steps TEMPLATE (docs/08 §7, §20).
# Run on the Pilot server as the deploy user, from the backend directory,
# after the code for the release has been checked out. Contains no secrets:
# configuration comes from backend/.env on the server.
#
#   FIRST=1 ./deploy-backend.sh   first initialization of the empty database
#   ./deploy-backend.sh           every later deployment
set -euo pipefail

cd "$(dirname "$0")/../../backend"

php artisan down --retry=60 || true

composer install --no-dev --optimize-autoloader --no-interaction   # also publishes Filament assets

php artisan migrate --force

if [[ "${FIRST:-0}" == "1" ]]; then
  # Reference data only (roles/permissions, Al-Breem taxonomy, relationship
  # types, disability types, assessment domains, need and assistance
  # categories). Creates no user, Family, Person or program.
  php artisan db:seed --force
fi

# ALWAYS: bring roles/permissions to the canonical baseline, then prove it.
php artisan db:seed --class=RolePermissionSeeder --force
php artisan famboook:verify-permissions

php artisan optimize          # config, routes, views, events
php artisan filament:optimize

# Queue workers (FB-NOTIFY-01E.1): ask any running `queue:work` to exit after
# its current job so systemd starts it again on the new code and configuration.
# It only writes a cache flag — it starts nothing, and with no worker installed
# it changes nothing. Never fatal: a failure here must not leave the site in
# maintenance mode; restart the worker by hand instead
# (sudo systemctl restart famboook-notifications-worker).
php artisan queue:restart || echo "WARNING: queue:restart failed — restart famboook-notifications-worker manually if it is installed." >&2

php artisan up
echo "Backend deployed. Next: create the first SUPER_ADMIN (first time only) and run the smoke test (docs/08 §17)."
