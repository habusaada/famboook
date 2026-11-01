#!/usr/bin/env bash
# FB-DEVOPS-01 — integration test of isolated builds with the real Next.js, in this checkout.
#
#   FAMBOOOK_BUILD_INTEGRATION=1 bash deploy/tests/build-frontend-integration.sh
#
# Runs real `next build`s (two isolated, one refused, one default) and one `next start` on
# 127.0.0.1:3911 (several minutes).
# Plants stale generated types that reference removed routes and a source type error,
# and removes only the files it planted. The test releases it builds stay in
# frontend/.builds/ (nothing under .builds/ is ever deleted automatically). Never on Production.
set -uo pipefail

[[ ${FAMBOOOK_BUILD_INTEGRATION:-} == 1 ]] || { echo "set FAMBOOOK_BUILD_INTEGRATION=1 to run (real builds, several minutes)"; exit 2; }
[[ $(id -u) -ne 0 ]] || { echo "do not run as root"; exit 2; }

ROOT=$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)
FRONTEND=$ROOT/frontend
cd "$FRONTEND" || exit 2
export NEXT_PUBLIC_API_URL=${NEXT_PUBLIC_API_URL:-https://api.example.invalid}
export NEXT_TELEMETRY_DISABLED=1
STAMP=$(date +%s)
OK_ID=it-$STAMP-ok
ERR_ID=it-$STAMP-err
PASSED=0 FAILED=0 SKIPPED=0
PLANTED=()
SHIM=""

pass() { PASSED=$((PASSED + 1)); printf 'PASS  %s\n' "$1"; }
fail() { FAILED=$((FAILED + 1)); printf 'FAIL  %s\n' "$1"; [[ -n ${2:-} ]] && printf '%s\n' "$2" | tail -n 25 | sed 's/^/      /'; }
plant() { mkdir -p "$(dirname "$1")" && printf '%s\n' "$2" >"$1" && PLANTED+=("$1"); }
cleanup() {
  local path
  for path in "${PLANTED[@]}"; do rm -f "$path"; done
  rmdir "$FRONTEND/.next/it-$STAMP-legacy/types" "$FRONTEND/.next/it-$STAMP-legacy" \
    "$FRONTEND/.builds/it-$STAMP-older/types" "$FRONTEND/.builds/it-$STAMP-older" 2>/dev/null
  [[ -n $SHIM ]] && rm -rf "$SHIM"
}
trap cleanup EXIT

if ! command -v flock >/dev/null 2>&1; then
  [[ ${FAMBOOOK_TESTS_NO_SKIP:-} != 1 ]] || { echo "flock is required here"; exit 2; }
  # Local machines without util-linux (Git Bash): a stand-in that never blocks. The
  # lock itself is covered by deploy/tests/build-frontend.test.sh where flock exists.
  SHIM=$(mktemp -d)
  printf '#!/usr/bin/env bash\nexit 0\n' >"$SHIM/flock"
  chmod +x "$SHIM/flock"
  export PATH="$SHIM:$PATH"
  echo "NOTE  no real flock: using a non-blocking stand-in for this run"
fi

STATUS_BEFORE=$(git -C "$ROOT" status --porcelain)
TSCONFIG_BEFORE=$(git -C "$ROOT" hash-object frontend/tsconfig.json)
NEXT_ENV_BEFORE=$(cat next-env.d.ts 2>/dev/null || true)

# Stale generated types of removed routes, as left by an earlier default build, an
# earlier `next dev`, an earlier release and a legacy .next/<id> release.
# Written like Next.js's validator.ts: a type-level import of a missing page is TS2307 (a bare
# side-effect `import "…"` would not be checked). <up> is the path from the file to frontend/.
stale() { printf 'type Welcome = typeof import("%sapp/(public)/welcome/page.js");
type Staff = typeof import("%sapp/(staff)/page.js");
export type { Welcome, Staff };' "$1" "$1"; }
plant ".next/types/it-$STAMP-stale.ts" "$(stale ../../)"
plant ".next/dev/types/it-$STAMP-stale.ts" "$(stale ../../../)"
plant ".builds/it-$STAMP-older/types/validator.ts" "$(stale ../../../)"
plant ".next/it-$STAMP-legacy/types/validator.ts" "$(stale ../../../)"

# 1. The project's own tsconfig.json really picks up the stale .next types (the root cause).
OUT=$(npx tsc --noEmit -p tsconfig.json 2>&1)
if [[ $? -ne 0 && $OUT == *"TS2307"* && $OUT == *"welcome/page.js"* && $OUT == *"it-$STAMP-stale.ts"* ]]; then pass "tsconfig.json type-checks stale .next types: TS2307 (root cause reproduced)"; else fail "root cause reproduced" "${OUT:-(tsc printed nothing)}"; fi

# 2. An isolated build succeeds despite all of them.
OUT=$(bash "$ROOT/deploy/scripts/build-frontend.sh" "$OK_ID" 2>&1)
if [[ $? -eq 0 && -s .builds/$OK_ID/BUILD_ID ]]; then pass "an isolated build succeeds despite stale types in .next/types, .next/dev/types, other releases and legacy releases"; else fail "isolated build with stale types" "$OUT"; fi
grep -q '"status": "succeeded"' ".builds/$OK_ID/famboook-release.json" 2>/dev/null && pass "the release is marked succeeded" || fail "the release is marked succeeded"

# 3. What TypeScript actually includes for the release (not assumed from glob rules).
FILES=$(npx tsc --listFilesOnly -p ".builds/$OK_ID.tsconfig.json" 2>&1 | tr '\\' '/')
[[ $FILES == *"/.builds/$OK_ID/types/validator.ts"* ]] && pass "the release's own route validator is type-checked" || fail "own validator included" "$FILES"
# The only .next/ files allowed are the two that the restored next-env.d.ts imports (during the
# build, Next.js points next-env.d.ts at the release's own types instead).
[[ -z $(grep -E "it-$STAMP-(stale|legacy)|/\.next/dev/|/\.next/types/validator\.ts|/\.next/it-" <<<"$FILES") ]]   && pass "no stale type file under .next/ is included" || fail "no stale .next/ file included" "$(grep '/\.next/' <<<"$FILES")"
[[ -z $(grep '/\.next/' <<<"$FILES" | grep -vE '/\.next/types/(routes|root-params)\.d\.ts$') ]]   && pass "only next-env.d.ts's own imports come from .next/" || fail "unexpected .next/ files" "$(grep '/\.next/' <<<"$FILES")"
[[ -z $(grep '/\.builds/' <<<"$FILES" | grep -v "/\.builds/$OK_ID/") ]] && pass "no other release's types are included" || fail "other releases excluded" "$(grep '/\.builds/' <<<"$FILES")"
[[ $FILES == *"/app/(public)/page.tsx"* && $FILES == *"/lib/navigation.ts"* ]] && pass "the application source is included" || fail "application source included"
BASE_FILES=$(npx tsc --listFilesOnly -p tsconfig.json 2>&1 | tr '\\' '/')
[[ $BASE_FILES != *"/.builds/"* ]] && pass "tsconfig.json itself never includes .builds/" || fail "tsconfig.json excludes .builds/" "$(grep '/\.builds/' <<<"$BASE_FILES")"

# 4. A real type error in the application source still fails the build.
plant "lib/it-$STAMP-type-error.ts" 'export const mustFail: number = "not a number";'
OUT=$(bash "$ROOT/deploy/scripts/build-frontend.sh" "$ERR_ID" 2>&1)
if [[ $? -ne 0 && $OUT == *"it-$STAMP-type-error.ts"* ]]; then pass "a type error in the source fails the build"; else fail "a source type error fails the build" "$OUT"; fi
grep -q '"status": "failed"' ".builds/$ERR_ID/famboook-release.json" 2>/dev/null && pass "the failed release is marked failed" || fail "the failed release is marked failed"
rm -f "lib/it-$STAMP-type-error.ts"

# 5. Tracked files and next-env.d.ts.
[[ $(git -C "$ROOT" hash-object frontend/tsconfig.json) == "$TSCONFIG_BEFORE" ]] && pass "tracked tsconfig.json is unchanged" || fail "tracked tsconfig.json is unchanged" "$(git -C "$ROOT" diff -- frontend/tsconfig.json)"
[[ $(cat next-env.d.ts 2>/dev/null || true) == "$NEXT_ENV_BEFORE" ]] && pass "next-env.d.ts is unchanged after isolated builds" || fail "next-env.d.ts is unchanged"

# 6. The release starts and answers (next start on a spare port).
OUT=$(bash "$ROOT/deploy/scripts/smoke-frontend-release.sh" "$OK_ID" 3911 2>&1)
if [[ $? -eq 0 ]]; then pass "the release passes the smoke test on 127.0.0.1:3911"; else fail "smoke test" "$OUT"; fi

# 7. A default `next build` refuses to run beside a legacy release.
plant ".next/it-$STAMP-legacy/BUILD_ID" "planted"
OUT=$(node node_modules/next/dist/bin/next build 2>&1)
if [[ $? -ne 0 && $OUT == *"would delete"* && -s .next/it-$STAMP-legacy/BUILD_ID ]]; then pass "a default build refuses to run beside a legacy .next/<id> release, which survives"; else fail "default build refused beside a legacy release" "$OUT"; fi

# 8. A default `next build` cannot delete or change an isolated release. It empties .next/ except
# cache, dev, lock and trace, so it is not run when any folder there is not Next.js's own.
cleanup; PLANTED=()
OTHERS=$(find .next -mindepth 1 -maxdepth 1 -type d 2>/dev/null | grep -vE '/(build|cache|dev|diagnostics|lock|server|static|types)$')
if [[ -n $OTHERS ]]; then
  if [[ ${FAMBOOOK_TESTS_NO_SKIP:-} == 1 ]]; then
    fail "a default build leaves the isolated release unchanged" "could not run: it would delete $OTHERS"
  else
    SKIPPED=$((SKIPPED + 1)); printf 'SKIP  a default build leaves the isolated release unchanged (it would delete: %s)\n' "$OTHERS"
  fi
else
  SNAP=$(cd ".builds/$OK_ID" && find . -type f ! -path './cache/*' | sort | xargs git hash-object | sha1sum)
  OUT=$(node node_modules/next/dist/bin/next build 2>&1)
  if [[ $? -eq 0 ]]; then pass "a default build still succeeds"; else fail "a default build still succeeds" "$OUT"; fi
  [[ $(cd ".builds/$OK_ID" && find . -type f ! -path './cache/*' | sort | xargs git hash-object | sha1sum) == "$SNAP" ]] \
    && pass "a default build leaves the isolated release byte-for-byte unchanged" || fail "the isolated release survives a default build"
fi

# 9. Nothing tracked or unignored changed in the checkout.
[[ $(git -C "$ROOT" status --porcelain) == "$STATUS_BEFORE" ]] && pass "git status is the same as before the run" \
  || fail "git status unchanged" "$(diff <(echo "$STATUS_BEFORE") <(git -C "$ROOT" status --porcelain))"

printf '\n%d passed, %d failed, %d skipped. Test releases kept: frontend/.builds/%s, frontend/.builds/%s\n' "$PASSED" "$FAILED" "$SKIPPED" "$OK_ID" "$ERR_ID"
(( FAILED == 0 ))
