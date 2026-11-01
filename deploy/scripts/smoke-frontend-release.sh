#!/usr/bin/env bash
# Famboook Next.js — smoke test of a built release on a spare localhost port (FB-DEVOPS-01).
# Runbook: docs/deployment/FB-DEVOPS-01-ISOLATED-FRONTEND-BUILDS.md
#
#   deploy/scripts/smoke-frontend-release.sh <release-id> [port]    (default port 3901)
#
# Starts a second `next start` for frontend/.builds/<release-id> on 127.0.0.1,
# checks the public routes, then stops it. It does not touch the active service.
set -euo pipefail

die() { printf 'smoke-frontend-release: %s\n' "$*" >&2; exit 1; }

[[ $# -ge 1 && $# -le 2 ]] || die "usage: smoke-frontend-release.sh <release-id> [port]"
RELEASE_ID=$1
PORT=${2:-3901}
[[ $RELEASE_ID =~ ^[a-z0-9][a-z0-9-]{0,39}$ ]] || die "invalid release id '$RELEASE_ID'"
[[ $PORT =~ ^[0-9]{4,5}$ ]] && (( PORT >= 1024 && PORT <= 65535 && PORT != 3001 )) \
  || die "invalid port '$PORT': use 1024-65535, not the active service's 3001"
for tool in node curl; do
  command -v "$tool" >/dev/null 2>&1 || die "required tool not found: $tool"
done

SCRIPT_DIR=$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)
FRONTEND=$(cd "$SCRIPT_DIR/../.." && pwd)/frontend
RELEASE_DIR=$FRONTEND/.builds/$RELEASE_ID
NEXT_CLI=$FRONTEND/node_modules/next/dist/bin/next
[[ -s $RELEASE_DIR/BUILD_ID ]] || die "release '$RELEASE_ID' has no build in $RELEASE_DIR"
grep -q '"status": "succeeded"' "$RELEASE_DIR/famboook-release.json" 2>/dev/null \
  || die "release '$RELEASE_ID' is not marked succeeded in famboook-release.json"
if curl -s -o /dev/null --max-time 2 "http://127.0.0.1:$PORT/"; then
  die "port $PORT is already in use"
fi

LOG=$(mktemp)
(cd "$FRONTEND" && FAMBOOOK_NEXT_DIST_DIR=".builds/$RELEASE_ID" NODE_ENV=production \
  exec node "$NEXT_CLI" start -H 127.0.0.1 -p "$PORT") >"$LOG" 2>&1 &
SERVER_PID=$!
stop_server() {
  kill "$SERVER_PID" 2>/dev/null || true
  wait "$SERVER_PID" 2>/dev/null || true
  rm -f "$LOG"
}
trap stop_server EXIT

for _ in $(seq 1 60); do
  curl -s -o /dev/null --max-time 2 "http://127.0.0.1:$PORT/login" && break
  kill -0 "$SERVER_PID" 2>/dev/null || { cat "$LOG" >&2; die "the server stopped during start-up"; }
  sleep 1
done

FAILED=0
# check <host> <path> <expected status> [expected Location] [expected header line]
check() {
  local host=$1 path=$2 want=$3 location=${4:-} header=${5:-} response status
  response=$(curl -s -o /dev/null -D - --max-time 10 -H "Host: $host" "http://127.0.0.1:$PORT$path" | tr -d '\r')
  status=$(printf '%s\n' "$response" | awk 'NR==1 {print $2}')
  local ok=1
  [[ $status == "$want" ]] || ok=0
  if [[ -n $location ]] && ! printf '%s\n' "$response" | grep -qi "^location: $location$"; then ok=0; fi
  if [[ -n $header ]] && ! printf '%s\n' "$response" | grep -qi "^$header"; then ok=0; fi
  if (( ok )); then
    printf 'PASS  %-18s %-26s %s\n' "$host" "$path" "$status"
  else
    printf 'FAIL  %-18s %-26s got %s, expected %s %s %s\n' "$host" "$path" "${status:-no answer}" "$want" "$location" "$header"
    FAILED=1
  fi
}

check famboook.com / 200
check famboook.com /welcome 308 /
check app.famboook.com / 307 /family
check famboook.com /login 200
check famboook.com /family/login 200
check famboook.com /robots.txt 200
check famboook.com /sitemap.xml 200
check famboook.com /verify/not-a-real-token 200 "" "x-robots-tag: noindex"

(( FAILED == 0 )) || die "release '$RELEASE_ID' FAILED the smoke test"
printf 'smoke-frontend-release: release %s passed on 127.0.0.1:%s\n' "$RELEASE_ID" "$PORT"
