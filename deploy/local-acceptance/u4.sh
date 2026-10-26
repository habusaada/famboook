#!/usr/bin/env bash
# FB-CIVIL-01A-UI U4 — LOCAL operational acceptance launcher (SYNTHETIC data only).
#
# Runs the acceptance stack against the disposable database built by
# u4-setup.php (127.0.0.1:5433/famboook_u4_test), on ports that do not clash
# with the normal development stack:
#
#   API (php artisan serve)        http://localhost:8010   env: $STATE/web.env
#   Staff Portal (next dev)        http://localhost:3010   NEXT_PUBLIC_API_URL=http://localhost:8010
#   identity import worker         backend/identity-import-worker, CLEAN environment = $STATE/worker.env only
#
# Credentials live only in $STATE (default %LOCALAPPDATA%/famboook-u4); backend/.env
# is never modified. Run each long-lived command in its own terminal.
#
#   u4.sh api | ui | worker [--once] | gate open|close | verify | status | restore-frontend | teardown
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
STATE="${FAMBOOOK_U4_STATE:-${LOCALAPPDATA:-/tmp}/famboook-u4}"
STATE="${STATE//\\//}"

need_state() {
  [[ -f "$STATE/web.env" && -f "$STATE/worker.env" ]] || { echo "[NO_ACCEPTANCE_STATE] run: php deploy/local-acceptance/u4-setup.php" >&2; exit 1; }
  # Never anything but the disposable acceptance database.
  grep -qx 'DB_PORT=5433' "$STATE/web.env" && grep -qx 'DB_DATABASE=famboook_u4_test' "$STATE/web.env" \
    && grep -qx 'DB_PORT=5433' "$STATE/worker.env" && grep -qx 'DB_DATABASE=famboook_u4_test' "$STATE/worker.env" \
    || { echo "[REFUSING_NON_ACCEPTANCE_TARGET]" >&2; exit 1; }
}

with_web_env() { ( set -a; . "$STATE/web.env"; set +a; cd "$ROOT/backend"; "$@" ); }

set_key() {   # set_key FILE KEY VALUE  (VALUE empty: remove the key)
  local file="$1" key="$2" value="$3"
  grep -v "^${key}=" "$file" > "$file.tmp" || true
  [[ -n "$value" ]] && echo "${key}=${value}" >> "$file.tmp"
  mv "$file.tmp" "$file"
}

case "${1:-}" in
  api)
    need_state
    with_web_env php artisan serve --host=127.0.0.1 --port=8010
    ;;
  ui)
    need_state
    cd "$ROOT/frontend"
    # Own distDir (.next/u4-acceptance): next dev locks <distDir>/, so this can run beside
    # the normal development server (.next). next dev also rewrites next-env.d.ts and
    # adds its distDir's type paths to tsconfig.json: keep the developer's copies and
    # put them back when this server stops (after a forced kill: u4.sh restore-frontend).
    cp -p next-env.d.ts "$STATE/next-env.d.ts.backup" 2>/dev/null || true
    cp -p tsconfig.json "$STATE/tsconfig.json.backup" 2>/dev/null || true
    trap 'cp -p "$STATE/next-env.d.ts.backup" next-env.d.ts 2>/dev/null; cp -p "$STATE/tsconfig.json.backup" tsconfig.json 2>/dev/null || true' EXIT
    FAMBOOOK_NEXT_DIST_DIR=.next/u4-acceptance NEXT_PUBLIC_API_URL=http://localhost:8010 npx next dev -p 3010
    ;;
  worker)
    need_state
    shift
    # Like systemd EnvironmentFile=: nothing from this shell, nothing from backend/.env.
    cd "$ROOT/backend"
    env -i PATH="$PATH" SystemRoot="${SYSTEMROOT:-${SystemRoot:-}}" WINDIR="${WINDIR:-}" TEMP="${TEMP:-/tmp}" TMP="${TMP:-/tmp}" \
      bash -c 'set -a; . "$0"; set +a; exec php identity-import-worker famboook:identity-ref-worker --instance=u4-worker "$@"' "$STATE/worker.env" "$@"
    ;;
  gate)
    need_state
    case "${2:-}" in
      open)
        echo "Opening the publication gate in the LOCAL ACCEPTANCE environment only (famboook_u4_test)."
        set_key "$STATE/web.env" CIVIL_IMPORT_PUBLISH_ENABLED true
        set_key "$STATE/worker.env" CIVIL_IMPORT_PUBLISH_ENABLED true
        set_key "$STATE/worker.env" IDENTITY_REF_PRODUCTION_APPROVAL U4-LOCAL-ACCEPTANCE
        ;;
      close)
        set_key "$STATE/web.env" CIVIL_IMPORT_PUBLISH_ENABLED false
        set_key "$STATE/worker.env" CIVIL_IMPORT_PUBLISH_ENABLED false
        set_key "$STATE/worker.env" IDENTITY_REF_PRODUCTION_APPROVAL ""
        ;;
      *) echo "usage: u4.sh gate open|close" >&2; exit 2 ;;
    esac
    # civil-import.publish.approve exists only while the web gate is open: re-seed the role baseline.
    with_web_env php artisan db:seed --class=RolePermissionSeeder --force --no-interaction
    with_web_env php artisan famboook:verify-permissions | grep -E "publication gate|Apply gate" || true
    echo "Restart 'u4.sh api' and 'u4.sh worker' for the change to take effect."
    ;;
  verify)
    need_state
    with_web_env php artisan famboook:verify-permissions
    ;;
  status)
    need_state
    echo "state:  $STATE"
    echo "web:    publication $(grep '^CIVIL_IMPORT_PUBLISH_ENABLED=' "$STATE/web.env" | cut -d= -f2)"
    echo "worker: publication $(grep '^CIVIL_IMPORT_PUBLISH_ENABLED=' "$STATE/worker.env" | cut -d= -f2), authorization reference $(grep -q '^IDENTITY_REF_PRODUCTION_APPROVAL=' "$STATE/worker.env" && echo present || echo absent)"
    ls "$STATE/sources"
    ;;
  restore-frontend)
    cp -p "$STATE/next-env.d.ts.backup" "$ROOT/frontend/next-env.d.ts" && cp -p "$STATE/tsconfig.json.backup" "$ROOT/frontend/tsconfig.json"       && echo "frontend/next-env.d.ts and tsconfig.json restored"
    ;;
  teardown)
    php "$ROOT/deploy/local-acceptance/u4-teardown.php"
    ;;
  *)
    echo "usage: u4.sh api | ui | worker [--once] | gate open|close | verify | status | teardown" >&2
    exit 2
    ;;
esac
