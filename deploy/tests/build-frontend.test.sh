#!/usr/bin/env bash
# FB-DEVOPS-01 — tests of deploy/scripts/build-frontend.sh and the argument checks of
# the smoke and switch scripts, against a fake `next` in a throwaway Git repository.
# Fast, and touches nothing outside a temporary directory.
#
#   bash deploy/tests/build-frontend.test.sh
#
# The concurrency test needs a real `flock` (util-linux). Without one (e.g. Git Bash on
# Windows) it is reported as SKIPPED and the other tests use a stand-in that never blocks.
set -uo pipefail

ROOT=$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)
REAL_TS_NODE_MODULES=$ROOT/frontend/node_modules
[[ -d $REAL_TS_NODE_MODULES/typescript ]] || { echo "frontend/node_modules/typescript is required (npm ci)"; exit 2; }
WORK=$(mktemp -d)
trap 'rm -rf "$WORK"' EXIT
PASSED=0 FAILED=0 SKIPPED=0

pass() { PASSED=$((PASSED + 1)); printf 'PASS  %s\n' "$1"; }
fail() { FAILED=$((FAILED + 1)); printf 'FAIL  %s\n' "$1"; [[ -n ${2:-} ]] && printf '      %s\n' "$2"; }
# FAMBOOOK_TESTS_NO_SKIP=1 (CI): a test that cannot run is a failure.
skip() {
  if [[ ${FAMBOOOK_TESTS_NO_SKIP:-} == 1 ]]; then fail "$1" "could not run: $2"; return; fi
  SKIPPED=$((SKIPPED + 1)); printf 'SKIP  %s (%s)\n' "$1" "$2"
}

# Tools on PATH for the scripts under test.
BIN=$WORK/bin
mkdir -p "$BIN"
if command -v flock >/dev/null 2>&1; then
  REAL_FLOCK=1
else
  REAL_FLOCK=0
  printf '#!/usr/bin/env bash\nexit 0\n' >"$BIN/flock"
  chmod +x "$BIN/flock"
fi
export PATH="$BIN:$PATH"
export NEXT_PUBLIC_API_URL=https://api.example.invalid
# The generator requires TypeScript from the sandbox frontend; fall back to the real one.
export NODE_PATH=$REAL_TS_NODE_MODULES

# A sandbox checkout: the real scripts and tsconfig.json, a fake `next`.
new_repo() {
  local repo=$WORK/repo-$1
  mkdir -p "$repo/deploy/scripts" "$repo/frontend/app" "$repo/frontend/node_modules/next/dist/bin"
  cp "$ROOT/deploy/scripts/build-frontend.sh" "$ROOT/deploy/scripts/smoke-frontend-release.sh" \
    "$ROOT/deploy/scripts/switch-frontend-release.sh" "$repo/deploy/scripts/"
  cp "$ROOT/frontend/tsconfig.json" "$repo/frontend/tsconfig.json"
  printf '{ "name": "sandbox", "private": true }\n' >"$repo/frontend/package.json"
  printf 'export default {};\n' >"$repo/frontend/next.config.ts"
  printf 'export default function Page() { return null; }\n' >"$repo/frontend/app/page.tsx"
  printf '/// <reference types="next" />\nimport "./.next/types/routes.d.ts";\n' >"$repo/frontend/next-env.d.ts"
  printf '/.builds/\nnode_modules/\nnext-env.d.ts\n' >"$repo/frontend/.gitignore"
  printf '{ "name": "next", "version": "0.0.0-fake" }\n' >"$repo/frontend/node_modules/next/package.json"
  cat >"$repo/frontend/node_modules/next/dist/bin/next" <<'NODE'
// Fake `next build`: FAKE_NEXT_MODE = ok (default) | fail | touch-tracked | slow.
const fs = require("node:fs");
const dist = process.env.FAMBOOOK_NEXT_DIST_DIR;
const mode = process.env.FAKE_NEXT_MODE || "ok";
if (process.argv[2] !== "build" || !dist) process.exit(64);
if (!fs.existsSync(`${dist}.tsconfig.json`)) { console.error("no release tsconfig"); process.exit(65); }
fs.copyFileSync(`${dist}.tsconfig.json`, `${process.env.FAKE_RECORD_DIR}/seen.tsconfig.json`);
fs.writeFileSync("next-env.d.ts", `import "./${dist}/types/routes.d.ts";\n`); // as Next.js does
if (mode === "fail") process.exit(1);
if (mode === "touch-tracked") fs.appendFileSync("tsconfig.json", "\n");
if (mode === "slow") Atomics.wait(new Int32Array(new SharedArrayBuffer(4)), 0, 0, 4000);
fs.mkdirSync(`${dist}/server`, { recursive: true });
fs.mkdirSync(`${dist}/types`, { recursive: true });
fs.writeFileSync(`${dist}/types/validator.ts`, "export {};\n");
fs.writeFileSync(`${dist}/routes-manifest.json`, "{}\n");
fs.writeFileSync(`${dist}/BUILD_ID`, "fake-build-id\n");
NODE
  git -C "$repo" init -q
  git -C "$repo" config core.autocrlf false
  git -C "$repo" add -A
  git -C "$repo" -c user.email=t@example.invalid -c user.name=t commit -qm init
  mkdir -p "$WORK/record-$1"
  printf '%s' "$repo"
}

build() { # build <repo> <id> [env...]  → exit status; output in $OUT
  local repo=$1 id=$2; shift 2
  OUT=$(env FAKE_RECORD_DIR="$WORK/record-${repo##*/repo-}" "$@" bash "$repo/deploy/scripts/build-frontend.sh" "$id" 2>&1)
}

# 1. Release identifiers.
R=$(new_repo ids)
for id in a 0 fb-web-05 20261031-1a2b3c4 "$(printf 'a%.0s' {1..40})"; do
  if build "$R" "$id"; then pass "accepts release id '$id'"; else fail "accepts release id '$id'" "$OUT"; fi
done
for id in "" UPPER -x a/b ../x .hidden "a b" a.b /abs "$(printf 'a%.0s' {1..41})" "x;rm"; do
  if build "$R" "$id"; then fail "refuses release id '$id'"; elif [[ $OUT == *"invalid release id"* || $OUT == *usage* ]]; then pass "refuses release id '$id'"; else fail "refuses release id '$id'" "$OUT"; fi
done
[[ -z $(find "$R/frontend/.builds" -mindepth 1 -maxdepth 1 ! -name '.*' -printf '%f\n' | grep -vE '^(a|0|fb-web-05|20261031-1a2b3c4|a{40})(\.tsconfig\.json)?$') ]] \
  && pass "a refused id creates nothing" || fail "a refused id creates nothing" "$(ls -a "$R/frontend/.builds")"

# 2. Root, missing tools and settings.
printf '#!/usr/bin/env bash\n[[ $1 == -u ]] && { echo 0; exit 0; }\nexec /usr/bin/id "$@"\n' >"$WORK/id"
chmod +x "$WORK/id"
R=$(new_repo root)
if OUT=$(PATH="$WORK:$PATH" FAKE_RECORD_DIR="$WORK/record-root" bash "$R/deploy/scripts/build-frontend.sh" r1 2>&1); then fail "refuses to run as root"; elif [[ $OUT == *"as root"* && ! -e $R/frontend/.builds/r1 ]]; then pass "refuses to run as root"; else fail "refuses to run as root" "$OUT"; fi
if build "$R" r2 NEXT_PUBLIC_API_URL=; then fail "requires NEXT_PUBLIC_API_URL"; elif [[ $OUT == *NEXT_PUBLIC_API_URL* ]]; then pass "requires NEXT_PUBLIC_API_URL"; else fail "requires NEXT_PUBLIC_API_URL" "$OUT"; fi
mv "$R/frontend/node_modules/next" "$R/frontend/node_modules/next.off"
if build "$R" r3; then fail "requires an installed Next.js and never installs it"; elif [[ $OUT == *"install dependencies first"* ]]; then pass "requires an installed Next.js and never installs it"; else fail "requires an installed Next.js" "$OUT"; fi
mv "$R/frontend/node_modules/next.off" "$R/frontend/node_modules/next"

# 3. A successful build: release, generated tsconfig, metadata, next-env.d.ts restored.
R=$(new_repo ok)
OK_REPO=$R
ENV_BEFORE=$(cat "$R/frontend/next-env.d.ts")
TS_BEFORE=$(git -C "$R" hash-object frontend/tsconfig.json)
if build "$R" rel-1; then pass "builds a release"; else fail "builds a release" "$OUT"; fi
[[ -s $R/frontend/.builds/rel-1/BUILD_ID ]] && pass "writes the release to .builds/<id>" || fail "writes the release to .builds/<id>"
SEEN=$WORK/record-ok/seen.tsconfig.json
node -e '
  const ts = require("typescript"); const c = ts.readConfigFile(process.argv[1], ts.sys.readFile).config;
  const want = ["../next-env.d.ts", "../**/*.ts", "../**/*.tsx", "../**/*.mts", "./rel-1/types/**/*.ts"];
  const ok = c.extends === "../tsconfig.json" && JSON.stringify(c.include) === JSON.stringify(want)
    && JSON.stringify(c.exclude) === JSON.stringify(["../node_modules"]) && !JSON.stringify(c).includes(".next");
  process.exit(ok ? 0 : 1);' "$SEEN" \
  && pass "the build sees a tsconfig with the project's sources and only its own generated types" \
  || fail "generated tsconfig content" "$(cat "$SEEN")"
[[ $(git -C "$R" hash-object frontend/tsconfig.json) == "$TS_BEFORE" ]] && pass "tracked tsconfig.json is unchanged" || fail "tracked tsconfig.json is unchanged"
[[ $(cat "$R/frontend/next-env.d.ts") == "$ENV_BEFORE" ]] && pass "next-env.d.ts is put back after the build" || fail "next-env.d.ts is put back" "$(cat "$R/frontend/next-env.d.ts")"
META=$R/frontend/.builds/rel-1/famboook-release.json
node -e '
  const m = require(process.argv[1]); const [commit, nodeVersion] = process.argv.slice(2);
  const iso = /^\d{4}-\d\d-\d\dT\d\d:\d\d:\d\dZ$/;
  const ok = m.release_id === "rel-1" && m.status === "succeeded" && !m.reason && m.dist_dir === ".builds/rel-1"
    && m.git_commit === commit && m.git_working_tree === "clean" && iso.test(m.build_started_at) && iso.test(m.build_finished_at)
    && m.build_id === "fake-build-id" && m.node_version === nodeVersion && m.next_version === "0.0.0-fake" && m.builder;
  process.exit(ok ? 0 : 1);' "$META" "$(git -C "$R" rev-parse HEAD)" "$(node -p process.version)" \
  && pass "records the release metadata" || fail "records the release metadata" "$(cat "$META")"
[[ -z $(git -C "$R" status --porcelain) ]] && pass "leaves no tracked or unignored change" || fail "leaves no change" "$(git -C "$R" status --porcelain)"

R=$(new_repo noenv)
rm "$R/frontend/next-env.d.ts"
if build "$R" rel-1; then pass "builds without an earlier next-env.d.ts"; else fail "builds without an earlier next-env.d.ts" "$OUT"; fi
[[ ! -e $R/frontend/next-env.d.ts ]] && pass "does not leave a next-env.d.ts that points at the release" || fail "leaves no next-env.d.ts"
R=$OK_REPO

# 4. Existing releases are never overwritten, and a new release leaves them alone.
printf 'keep\n' >"$R/frontend/.builds/rel-1/marker"
SNAP=$(cd "$R/frontend/.builds/rel-1" && find . -type f -exec git hash-object {} + | sort)
if build "$R" rel-1; then fail "refuses an existing release id"; elif [[ $OUT == *"already exists"* ]]; then pass "refuses an existing release id"; else fail "refuses an existing release id" "$OUT"; fi
if build "$R" rel-2; then pass "builds a second release"; else fail "builds a second release" "$OUT"; fi
[[ $(cd "$R/frontend/.builds/rel-1" && find . -type f -exec git hash-object {} + | sort) == "$SNAP" ]] \
  && pass "an existing release is left byte-for-byte unchanged" || fail "an existing release is unchanged"
mkdir -p "$R/frontend/.builds/rel-3-taken"
if build "$R" rel-3-taken; then fail "refuses a directory that already exists"; else pass "refuses a directory that already exists"; fi
printf '{}\n' >"$R/frontend/.builds/rel-4.tsconfig.json"
if build "$R" rel-4; then fail "refuses a leftover generated tsconfig"; elif [[ ! -e $R/frontend/.builds/rel-4 ]]; then pass "refuses a leftover generated tsconfig"; else fail "refuses a leftover generated tsconfig"; fi

# 5. Failures.
if build "$R" rel-fail FAKE_NEXT_MODE=fail; then fail "reports a failed build"; else pass "reports a failed build"; fi
node -e 'const m = require(process.argv[1]); process.exit(m.status === "failed" && /status 1/.test(m.reason) ? 0 : 1)' "$R/frontend/.builds/rel-fail/famboook-release.json" \
  && pass "marks a failed release failed" || fail "marks a failed release failed"
[[ $(cat "$R/frontend/next-env.d.ts") == "$ENV_BEFORE" ]] && pass "next-env.d.ts is put back after a failure" || fail "next-env.d.ts after a failure"

R=$(new_repo touch)
if build "$R" rel-t FAKE_NEXT_MODE=touch-tracked; then fail "fails when the build changes a tracked file"; elif [[ $OUT == *"changed tracked files"* && $OUT == *frontend/tsconfig.json* ]]; then pass "fails when the build changes a tracked file, naming it"; else fail "detects a tracked change" "$OUT"; fi
[[ -n $(git -C "$R" diff --name-only -- frontend/tsconfig.json) ]] && pass "leaves that change in place for inspection (no revert)" || fail "leaves the change in place"

# 6. Changes made before the build are preserved and do not fail it.
R=$(new_repo dirty)
printf 'export const local = 1;\n' >>"$R/frontend/app/page.tsx"
printf '\n' >>"$R/frontend/tsconfig.json"
BEFORE=$(git -C "$R" diff)
if build "$R" rel-d; then pass "builds with pre-existing local changes"; else fail "builds with pre-existing local changes" "$OUT"; fi
[[ $(git -C "$R" diff) == "$BEFORE" ]] && pass "pre-existing local changes are preserved exactly" || fail "pre-existing changes are preserved"
node -e 'process.exit(require(process.argv[1]).git_working_tree === "modified" ? 0 : 1)' "$R/frontend/.builds/rel-d/famboook-release.json" \
  && pass "metadata records a modified working tree" || fail "metadata records a modified working tree"

STAGED_REPO=$(new_repo staged)
printf 'export const staged = 1;\n' >>"$STAGED_REPO/frontend/app/page.tsx"
git -C "$STAGED_REPO" add frontend/app/page.tsx
printf 'export const unstaged = 2;\n' >>"$STAGED_REPO/frontend/app/page.tsx"
printf 'keep\n' >"$STAGED_REPO/frontend/app/untracked.ts"
BEFORE=$(git -C "$STAGED_REPO" status --porcelain; git -C "$STAGED_REPO" diff; git -C "$STAGED_REPO" diff --cached; cat "$STAGED_REPO/frontend/app/untracked.ts")
if build "$STAGED_REPO" rel-s; then pass "builds with staged, unstaged and untracked changes"; else fail "builds with staged, unstaged and untracked changes" "$OUT"; fi
[[ $(git -C "$STAGED_REPO" status --porcelain; git -C "$STAGED_REPO" diff; git -C "$STAGED_REPO" diff --cached; cat "$STAGED_REPO/frontend/app/untracked.ts") == "$BEFORE" ]] \
  && pass "staged, unstaged and untracked changes are preserved exactly" || fail "staged, unstaged and untracked changes are preserved"
if build "$STAGED_REPO" rel-sf FAKE_NEXT_MODE=fail; then fail "a failed build reports failure"; else pass "a failed build reports failure with local changes present"; fi
[[ $(git -C "$STAGED_REPO" status --porcelain; git -C "$STAGED_REPO" diff; git -C "$STAGED_REPO" diff --cached; cat "$STAGED_REPO/frontend/app/untracked.ts") == "$BEFORE" ]] \
  && pass "a failed build leaves local changes exactly as they were" || fail "a failed build leaves local changes"

# An interrupted build leaves no success marker and restores next-env.d.ts.
R=$(new_repo term)
ENV_TERM=$(cat "$R/frontend/next-env.d.ts")
FAKE_RECORD_DIR="$WORK/record-term" FAKE_NEXT_MODE=slow bash "$R/deploy/scripts/build-frontend.sh" rel-int >/dev/null 2>&1 &
TERM_PID=$!
sleep 1.5
kill -TERM "$TERM_PID" 2>/dev/null
wait "$TERM_PID" 2>/dev/null
[[ ! -e $R/frontend/.builds/rel-int/famboook-release.json ]] && pass "an interrupted build has no release metadata" || fail "an interrupted build has no release metadata"
[[ $(cat "$R/frontend/next-env.d.ts") == "$ENV_TERM" ]] && pass "an interrupted build restores next-env.d.ts" || fail "an interrupted build restores next-env.d.ts" "$(cat "$R/frontend/next-env.d.ts")"
OUT=$(bash "$R/deploy/scripts/smoke-frontend-release.sh" rel-int 2>&1) && fail "smoke refuses an interrupted build" || pass "smoke refuses an interrupted build"

# 7. Only one build at a time.
if (( REAL_FLOCK )); then
  R=$(new_repo lock)
  (FAKE_RECORD_DIR="$WORK/record-lock" FAKE_NEXT_MODE=slow bash "$R/deploy/scripts/build-frontend.sh" slow-1 >/dev/null 2>&1) &
  SLOW=$!
  sleep 1.5
  if build "$R" second; then fail "refuses a concurrent build"; elif [[ $OUT == *"another build"* && ! -e $R/frontend/.builds/second ]]; then pass "refuses a concurrent build"; else fail "refuses a concurrent build" "$OUT"; fi
  wait "$SLOW" && pass "the first build still completes" || fail "the first build still completes"
  if build "$R" third; then pass "the lock is released afterwards"; else fail "the lock is released afterwards" "$OUT"; fi
else
  skip "refuses a concurrent build" "no real flock on this machine"
fi

# 8. Every source of this mechanism can be committed (a root `build/` ignore rule once hid one).
for path in frontend/lib/release-dir.ts frontend/next.config.ts frontend/tests/next-config-release-dir.test.ts \
  deploy/scripts/build-frontend.sh deploy/scripts/smoke-frontend-release.sh deploy/scripts/switch-frontend-release.sh \
  deploy/scripts/deploy-frontend.sh deploy/systemd/famboook-frontend.service \
  deploy/systemd/famboook-frontend.service.d/20-release.conf deploy/env/frontend-release.env.example \
  deploy/tests/build-frontend.test.sh deploy/tests/build-frontend-integration.sh \
  docs/deployment/FB-DEVOPS-01-ISOLATED-FRONTEND-BUILDS.md .github/workflows/frontend.yml; do
  if [[ -e $ROOT/$path ]] && ! git -C "$ROOT" check-ignore -q "$path"; then pass "not ignored by Git: $path"; else fail "missing or ignored by Git: $path"; fi
done
git -C "$ROOT" check-ignore -q frontend/.builds/x && pass ".builds/ is ignored by Git" || fail ".builds/ is ignored by Git"

# 9. Smoke and switch scripts: argument checks only (no server, no systemd).
R=$(new_repo args)
OUT=$(bash "$R/deploy/scripts/smoke-frontend-release.sh" "../x" 2>&1) && fail "smoke refuses an invalid id" || { [[ $OUT == *"invalid release id"* ]] && pass "smoke refuses an invalid id" || fail "smoke refuses an invalid id" "$OUT"; }
OUT=$(bash "$R/deploy/scripts/smoke-frontend-release.sh" rel 3001 2>&1) && fail "smoke refuses the live port" || { [[ $OUT == *"invalid port"* ]] && pass "smoke refuses the live port 3001" || fail "smoke refuses the live port" "$OUT"; }
OUT=$(bash "$R/deploy/scripts/smoke-frontend-release.sh" missing 2>&1) && fail "smoke refuses a missing release" || { [[ $OUT == *"has no build"* ]] && pass "smoke refuses a missing release" || fail "smoke refuses a missing release" "$OUT"; }
OUT=$(bash "$R/deploy/scripts/switch-frontend-release.sh" "../x" 2>&1) && fail "switch refuses an invalid id" || { [[ $OUT == *"invalid release id"* ]] && pass "switch refuses an invalid id" || fail "switch refuses an invalid id" "$OUT"; }
if [[ $(id -u) -ne 0 ]]; then
  OUT=$(bash "$R/deploy/scripts/switch-frontend-release.sh" rel 2>&1) && fail "switch requires root" || { [[ $OUT == *"run as root"* ]] && pass "switch requires root" || fail "switch requires root" "$OUT"; }
fi

# 10. Switch and rollback decisions, against a fake systemctl, curl and /proc (no real service).
sw_setup() {
  local name=$1 d
  d=$WORK/sw-$name
  mkdir -p "$d/deploy/scripts" "$d/etc" "$d/state" "$d/proc/123" "$d/bin"
  cp "$ROOT/deploy/scripts/switch-frontend-release.sh" "$d/deploy/scripts/"
  for rel in old new older; do
    mkdir -p "$d/frontend/.builds/$rel"
    printf 'x\n' >"$d/frontend/.builds/$rel/BUILD_ID"
    printf '{\n  "status": "succeeded"\n}\n' >"$d/frontend/.builds/$rel/famboook-release.json"
  done
  mkdir -p "$d/frontend/.builds/failed" "$d/frontend/.builds/interrupted"
  printf 'x\n' >"$d/frontend/.builds/failed/BUILD_ID"
  printf 'x\n' >"$d/frontend/.builds/interrupted/BUILD_ID"
  printf '{\n  "status": "failed"\n}\n' >"$d/frontend/.builds/failed/famboook-release.json"
  printf 'FAMBOOOK_NEXT_DIST_DIR=.builds/old\n' >"$d/etc/r.env"
  printf 'FAMBOOOK_NEXT_DIST_DIR=.builds/older\n' >"$d/etc/r.env.previous"
  printf 'PORT=3001\0FAMBOOOK_NEXT_DIST_DIR=.builds/old\0' >"$d/proc/123/environ"
  printf '123' >"$d/state/pid"
  cat >"$d/bin/id" <<'EOF'
#!/usr/bin/env bash
[[ ${1:-} == -u ]] && { echo 0; exit 0; }
exec /usr/bin/id "$@"
EOF
  cat >"$d/bin/systemctl" <<EOF
#!/usr/bin/env bash
D='$d'
case \$1 in
  show)
    case \$3 in
      EnvironmentFiles) [[ -e \$D/state/not-wired ]] || echo "\$D/etc/r.env (ignore_errors=no)" ;;
      MainPID) cat "\$D/state/pid"; echo ;;
    esac ;;
  is-active)
    [[ \$(cat "\$D/state/pid") != 0 ]] && { [[ \${2:-} == --quiet ]] || echo active; exit 0; }
    [[ \${2:-} == --quiet ]] || echo failed
    exit 3 ;;
  restart)
    echo restart >>"\$D/state/restarts"
    value=\$(sed -n 's/^FAMBOOOK_NEXT_DIST_DIR=//p' "\$D/etc/r.env")
    if grep -qxF "\$value" "\$D/state/wont-start" 2>/dev/null; then echo 0 >"\$D/state/pid"; exit 1; fi
    [[ -f \$D/state/override ]] && value=\$(cat "\$D/state/override")
    printf 'PORT=3001\\0FAMBOOOK_NEXT_DIST_DIR=%s\\0' "\$value" >"\$D/proc/123/environ"
    echo 123 >"\$D/state/pid" ;;
esac
EOF
  cat >"$d/bin/curl" <<EOF
#!/usr/bin/env bash
D='$d'
[[ \$(cat "\$D/state/pid") == 0 ]] && { printf 000; exit 7; }
value=\$(tr '\\0' '\\n' <"\$D/proc/123/environ" | sed -n 's/^FAMBOOOK_NEXT_DIST_DIR=//p')
if grep -qxF "\$value" "\$D/state/unhealthy" 2>/dev/null; then printf 500; else printf 200; fi
EOF
  cat >"$d/bin/mv" <<EOF
#!/usr/bin/env bash
# Refuses to replace the file named in state/mv-refuses.
D='$d'
last=\${!#}
if [[ -f \$D/state/mv-refuses && \$last == "\$(cat "\$D/state/mv-refuses")" ]]; then exit 1; fi
exec /usr/bin/mv "\$@"
EOF
  chmod +x "$d/bin/"*
  printf '%s' "$d"
}
sw() { # sw <dir> <argument>  → exit status; output in $OUT
  OUT=$(PATH="$1/bin:$PATH" FAMBOOOK_RELEASE_ENV_FILE="$1/etc/r.env" FAMBOOOK_PROC_DIR="$1/proc" FAMBOOOK_SWITCH_WAIT=1 \
    bash "$1/deploy/scripts/switch-frontend-release.sh" "$2" 2>&1)
}
val() { sed -n 's/^FAMBOOOK_NEXT_DIST_DIR=//p' "$1"; }
restarts() { if [[ -f $1/state/restarts ]]; then wc -l <"$1/state/restarts" | tr -d ' '; else echo 0; fi; }
unchanged() { # unchanged <dir> <label>: refused, nothing written, nothing restarted
  [[ $(val "$1/etc/r.env") == .builds/old && $(val "$1/etc/r.env.previous") == .builds/older && $(restarts "$1") == 0 ]] \
    && pass "$2: nothing changed, no restart" || fail "$2: nothing changed, no restart" "$OUT"
}
no_temps() { [[ -z $(find "$1/etc" -name 'r.env*.*' ! -name 'r.env.previous' ! -name 'r.env.lock') ]] && pass "$2: no temporary file left" || fail "$2: no temporary file left" "$(ls -a "$1/etc")"; }

D=$(sw_setup ok)
if sw "$D" new; then pass "switch: activates a succeeded release"; else fail "switch: activates a succeeded release" "$OUT"; fi
[[ $(val "$D/etc/r.env") == .builds/new && $(val "$D/etc/r.env.previous") == .builds/old && $(restarts "$D") == 1 ]] && pass "switch: records the new and previous values" || fail "switch: records values" "$OUT"
no_temps "$D" "switch"
if sw "$D" --rollback; then pass "rollback: returns to the previous release"; else fail "rollback: returns to the previous release" "$OUT"; fi
[[ $(val "$D/etc/r.env") == .builds/old && $(val "$D/etc/r.env.previous") == .builds/new ]] && pass "rollback: swaps the two values" || fail "rollback: swaps the two values" "$OUT"

for rel in failed interrupted missing; do
  D=$(sw_setup "ref-$rel")
  if sw "$D" "$rel"; then fail "switch refuses the $rel release"; else pass "switch refuses the $rel release"; fi
  unchanged "$D" "$rel release"
done
D=$(sw_setup same)
if sw "$D" old; then fail "switch refuses the active release"; else unchanged "$D" "the active release"; fi

D=$(sw_setup rollfailed)
printf 'FAMBOOOK_NEXT_DIST_DIR=.builds/failed\n' >"$D/etc/r.env.previous"
if sw "$D" --rollback; then fail "rollback refuses a failed release"; else pass "rollback refuses a failed release"; fi
[[ $(val "$D/etc/r.env") == .builds/old && $(restarts "$D") == 0 ]] && pass "rollback to a failed release: nothing changed" || fail "rollback to a failed release" "$OUT"

D=$(sw_setup noenv); rm "$D/etc/r.env"
if sw "$D" new; then fail "switch refuses a missing environment file"; elif [[ $OUT == *"is missing"* && ! -e $D/etc/r.env && $(restarts "$D") == 0 ]]; then pass "switch refuses a missing environment file and does not create it"; else fail "missing environment file" "$OUT"; fi
D=$(sw_setup twice); printf 'FAMBOOOK_NEXT_DIST_DIR=.builds/older\n' >>"$D/etc/r.env"
if sw "$D" new; then fail "switch refuses a value set twice"; elif [[ $OUT == *"exactly once"* && $(restarts "$D") == 0 ]]; then pass "switch refuses a value set twice"; else fail "value set twice" "$OUT"; fi
D=$(sw_setup bad); printf 'FAMBOOOK_NEXT_DIST_DIR=../../etc\n' >"$D/etc/r.env"
if sw "$D" new; then fail "switch refuses an invalid current value"; elif [[ $OUT == *"invalid value"* && $(restarts "$D") == 0 ]]; then pass "switch refuses an invalid current value"; else fail "invalid current value" "$OUT"; fi
D=$(sw_setup wired); : >"$D/state/not-wired"
if sw "$D" new; then fail "switch refuses a unit that does not read the file"; elif [[ $OUT == *"does not read"* ]]; then unchanged "$D" "unit without the drop-in"; else fail "unit without the drop-in" "$OUT"; fi

# Precedence is observed, not assumed.
D=$(sw_setup precheck); printf 'PORT=3001\0FAMBOOOK_NEXT_DIST_DIR=.next/from-environment\0' >"$D/proc/123/environ"
if sw "$D" new; then fail "switch refuses when another setting decides the running value"; elif [[ $OUT == *"something else sets"* ]]; then unchanged "$D" "running value differs from the file"; else fail "running value differs" "$OUT"; fi
D=$(sw_setup unset); printf 'PORT=3001\0' >"$D/proc/123/environ"
if sw "$D" new; then fail "switch refuses when the service does not have the variable"; else unchanged "$D" "variable absent from the running service"; fi
D=$(sw_setup override); printf '.builds/old' >"$D/state/override"
if sw "$D" new; then fail "switch fails when the file does not take effect"; elif [[ $OUT == *"was restored"* && $(val "$D/etc/r.env") == .builds/old && $(val "$D/etc/r.env.previous") == .builds/older ]]; then pass "switch: a file that does not take effect fails verification and is restored"; else fail "file without effect" "$OUT"; fi

# Failed restarts and health checks restore the earlier release.
D=$(sw_setup restart); printf '.builds/new\n' >"$D/state/wont-start"
if sw "$D" new; then fail "switch fails on a failed restart"; elif [[ $OUT == *"was restored"* && $(val "$D/etc/r.env") == .builds/old && $(val "$D/etc/r.env.previous") == .builds/older && $(restarts "$D") == 2 && $(cat "$D/state/pid") != 0 ]]; then pass "switch: a failed restart restores the earlier release and files"; else fail "failed restart" "$OUT"; fi
no_temps "$D" "failed restart"
D=$(sw_setup health); printf '.builds/new\n' >"$D/state/unhealthy"
if sw "$D" new; then fail "switch fails on a failed health check"; elif [[ $OUT == *"was restored"* && $(val "$D/etc/r.env") == .builds/old ]]; then pass "switch: a failed health check restores the earlier release"; else fail "failed health check" "$OUT"; fi
D=$(sw_setup firstprev); rm "$D/etc/r.env.previous"; printf '.builds/new\n' >"$D/state/unhealthy"
if sw "$D" new; then fail "first switch fails on a failed health check"; elif [[ ! -e $D/etc/r.env.previous && $(val "$D/etc/r.env") == .builds/old ]]; then pass "switch: restoring leaves no previous record when there was none"; else fail "restore without a previous record" "$OUT"; fi
D=$(sw_setup critical); printf '.builds/new\n.builds/old\n' >"$D/state/unhealthy"
if sw "$D" new; then fail "reports a failed restore"; elif [[ $OUT == *CRITICAL* && $(val "$D/etc/r.env") == .builds/old ]]; then pass "switch: a failed restore is reported as CRITICAL"; else fail "failed restore" "$OUT"; fi

D=$(sw_setup rbnone); rm "$D/etc/r.env.previous"
if sw "$D" --rollback; then fail "rollback refuses without a previous release"; elif [[ $OUT == *"no previous release"* && $(restarts "$D") == 0 ]]; then pass "rollback refuses without a previous release"; else fail "rollback without previous" "$OUT"; fi
D=$(sw_setup rbfail); printf '.builds/older\n' >"$D/state/unhealthy"
if sw "$D" --rollback; then fail "a failed rollback exits non-zero"; elif [[ $OUT == *"rollback verification FAILED"* && $(restarts "$D") == 1 ]]; then pass "a failed rollback stops and reports, without trying another release"; else fail "failed rollback" "$OUT"; fi

# Partial writes.
D=$(sw_setup mv1); printf '%s' "$D/etc/r.env.previous" >"$D/state/mv-refuses"
if sw "$D" new; then fail "a failed write of the previous value stops the switch"; else unchanged "$D" "failed write of the previous value"; fi
no_temps "$D" "failed write of the previous value"
D=$(sw_setup mv2); printf '%s' "$D/etc/r.env" >"$D/state/mv-refuses"
if sw "$D" new; then fail "a failed write of the current value stops the switch"; else unchanged "$D" "failed write of the current value"; fi
no_temps "$D" "failed write of the current value"

printf '\n%d passed, %d failed, %d skipped\n' "$PASSED" "$FAILED" "$SKIPPED"
(( FAILED == 0 ))
