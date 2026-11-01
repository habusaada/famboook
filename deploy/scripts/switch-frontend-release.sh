#!/usr/bin/env bash
# Famboook Next.js — switch the active release, roll back, or show it (FB-DEVOPS-01).
# Runbook: docs/deployment/FB-DEVOPS-01-ISOLATED-FRONTEND-BUILDS.md
#
#   sudo bash deploy/scripts/switch-frontend-release.sh <release-id>   activate frontend/.builds/<release-id>
#   sudo bash deploy/scripts/switch-frontend-release.sh --rollback     return to the previous release
#   sudo bash deploy/scripts/switch-frontend-release.sh --status       show the active and previous release
#
# Requires the migration (runbook §7): the unit reads the environment file, the file
# exists, and the running service really takes its value from it. A switch that fails
# verification puts the earlier release back by itself. A failed rollback does not
# choose another release: it reports the state and stops.
set -euo pipefail

die() { printf 'switch-frontend-release: %s\n' "$*" >&2; exit 1; }
say() { printf 'switch-frontend-release: %s\n' "$*"; }

ENV_FILE=${FAMBOOOK_RELEASE_ENV_FILE:-/etc/famboook/frontend-release.env}
PREVIOUS_FILE=$ENV_FILE.previous
UNIT=${FAMBOOOK_FRONTEND_UNIT:-famboook-frontend}
PORT=${FAMBOOOK_FRONTEND_PORT:-3001}
WAIT=${FAMBOOOK_SWITCH_WAIT:-30}
PROC=${FAMBOOOK_PROC_DIR:-/proc}
SCRIPT_DIR=$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)
FRONTEND=$(cd "$SCRIPT_DIR/../.." && pwd)/frontend

# A value for FAMBOOOK_NEXT_DIST_DIR: a release, or a legacy .next/<id> (rollback only).
valid_value() { [[ $1 =~ ^\.builds/[a-z0-9][a-z0-9-]{0,39}$ || $1 =~ ^\.next/[a-z0-9-]{1,40}$ ]]; }
value_in() { if [[ -f $1 ]]; then sed -n 's/^FAMBOOOK_NEXT_DIST_DIR=//p' "$1" | tail -n 1; fi; }
main_pid() { systemctl show -p MainPID --value "$UNIT"; }
# The value the running process really has, whatever set it.
running_value() {
  local pid
  pid=$(main_pid)
  if [[ $pid != 0 && -r $PROC/$pid/environ ]]; then
    tr '\0' '\n' <"$PROC/$pid/environ" | sed -n 's/^FAMBOOOK_NEXT_DIST_DIR=//p'
  fi
}

[[ $# -eq 1 ]] || die "usage: switch-frontend-release.sh <release-id> | --rollback | --status"
case $1 in
  --status|--rollback) MODE=${1#--} ;;
  *)
    [[ $1 =~ ^[a-z0-9][a-z0-9-]{0,39}$ ]] || die "invalid release id '$1'"
    MODE=switch
    TARGET=.builds/$1
    ;;
esac
[[ $(id -u) -eq 0 ]] || die "run as root (it writes $ENV_FILE and restarts $UNIT)"
for tool in systemctl curl flock; do
  command -v "$tool" >/dev/null 2>&1 || die "required tool not found: $tool"
done

if [[ $MODE == status ]]; then
  CURRENT=$(value_in "$ENV_FILE")
  printf 'configured: %s\nrunning:    %s\nprevious:   %s\n' "${CURRENT:-(none)}" "$(running_value)" "$(value_in "$PREVIOUS_FILE")"
  if [[ -n $CURRENT && -f $FRONTEND/$CURRENT/famboook-release.json ]]; then
    cat "$FRONTEND/$CURRENT/famboook-release.json"
  fi
  exit 0
fi

# Preconditions. None of these changes anything.
systemctl show -p EnvironmentFiles --value "$UNIT" | grep -qF "$ENV_FILE" \
  || die "$UNIT does not read $ENV_FILE: install the 20-release.conf drop-in first (runbook §7)"
[[ -f $ENV_FILE ]] || die "$ENV_FILE is missing: create it with the current value first (runbook §7)"
[[ $(grep -c '^FAMBOOOK_NEXT_DIST_DIR=' "$ENV_FILE" || true) == 1 ]] \
  || die "$ENV_FILE must set FAMBOOOK_NEXT_DIST_DIR exactly once"
CURRENT=$(value_in "$ENV_FILE")
valid_value "$CURRENT" || die "$ENV_FILE holds the invalid value '$CURRENT'"
PREVIOUS=$(value_in "$PREVIOUS_FILE")

# One switch at a time.
exec 8>"$ENV_FILE.lock"
flock -n 8 || die "another switch holds $ENV_FILE.lock"

if [[ $MODE == rollback ]]; then
  [[ -n $PREVIOUS ]] || die "no previous release is recorded in $PREVIOUS_FILE"
  TARGET=$PREVIOUS
else
  # An Environment= assignment, an ExecStart wrapper or another file may win over
  # this file. Only the running process shows which: compare before changing anything.
  if [[ $(main_pid) != 0 && $(running_value) != "$CURRENT" ]]; then
    die "$UNIT runs with '$(running_value)', not '$CURRENT' from $ENV_FILE: something else sets FAMBOOOK_NEXT_DIST_DIR (runbook §7). Nothing was changed."
  fi
fi
valid_value "$TARGET" || die "refusing the invalid value '$TARGET'"
[[ -s $FRONTEND/$TARGET/BUILD_ID ]] || die "$FRONTEND/$TARGET has no build"
if [[ $TARGET == .builds/* ]]; then
  grep -q '"status": "succeeded"' "$FRONTEND/$TARGET/famboook-release.json" 2>/dev/null \
    || die "$TARGET is not marked succeeded in famboook-release.json"
fi
[[ $TARGET != "$CURRENT" ]] || die "$TARGET is already the configured release"

TMP_CURRENT="" TMP_PREVIOUS=""
trap 'rm -f "$TMP_CURRENT" "$TMP_PREVIOUS"' EXIT

temp_with_value() {
  local tmp
  tmp=$(mktemp "$1.XXXXXX")
  printf '# Managed by deploy/scripts/switch-frontend-release.sh (FB-DEVOPS-01).\nFAMBOOOK_NEXT_DIST_DIR=%s\n' "$2" >"$tmp"
  chmod 0644 "$tmp"
  sync "$tmp"
  printf '%s' "$tmp"
}

# Writes both files in full first, then renames them, so a reader never sees a partial
# file and a full disk stops the switch before anything changes. <current> <previous>;
# an empty <previous> removes the record. If the second rename fails the first is undone.
write_state() {
  local current=$1 previous=$2 before
  before=$(value_in "$PREVIOUS_FILE")
  TMP_CURRENT=$(temp_with_value "$ENV_FILE" "$current")
  if [[ -n $previous ]]; then
    TMP_PREVIOUS=$(temp_with_value "$PREVIOUS_FILE" "$previous")
    mv -f "$TMP_PREVIOUS" "$PREVIOUS_FILE" || die "could not write $PREVIOUS_FILE; nothing was activated"
  else
    rm -f "$PREVIOUS_FILE"
  fi
  if ! mv -f "$TMP_CURRENT" "$ENV_FILE"; then
    if [[ -n $before ]]; then
      TMP_PREVIOUS=$(temp_with_value "$PREVIOUS_FILE" "$before")
      mv -f "$TMP_PREVIOUS" "$PREVIOUS_FILE" || true
    else
      rm -f "$PREVIOUS_FILE"
    fi
    die "could not write $ENV_FILE; nothing was activated"
  fi
}

# Restarts and waits until the service is active, really runs with <value>, and /login
# answers 200. A failed restart is not fatal here: it ends as a failed verification.
restart_and_verify() {
  local want=$1 code running i
  systemctl restart "$UNIT" || say "systemctl restart $UNIT failed"
  for ((i = 0; i < WAIT; i++)); do
    code=$(curl -s -o /dev/null -w '%{http_code}' --max-time 5 "http://127.0.0.1:$PORT/login" || true)
    running=$(running_value)
    if systemctl is-active --quiet "$UNIT" && [[ $running == "$want" && $code == 200 ]]; then
      return 0
    fi
    sleep 1
  done
  VERIFY_DETAIL="active: $(systemctl is-active "$UNIT" || true), running: ${running:-unknown}, /login: ${code:-none}"
  return 1
}

write_state "$TARGET" "$CURRENT"
say "$CURRENT -> $TARGET; restarting $UNIT"
if restart_and_verify "$TARGET"; then
  say "$TARGET is active and answers on 127.0.0.1:$PORT"
  exit 0
fi

if [[ $MODE == rollback ]]; then
  die "rollback verification FAILED ($VERIFY_DETAIL). Configured: $TARGET; the release rolled back from: $CURRENT. Inspect: journalctl -u $UNIT"
fi

FAILED_DETAIL=$VERIFY_DETAIL
say "verification of $TARGET FAILED ($FAILED_DETAIL); restoring $CURRENT"
write_state "$CURRENT" "$PREVIOUS"
if restart_and_verify "$CURRENT"; then
  die "switch to $TARGET FAILED and $CURRENT was restored and answers ($FAILED_DETAIL)"
fi
die "CRITICAL: switch to $TARGET FAILED ($FAILED_DETAIL) and restoring $CURRENT FAILED too ($VERIFY_DETAIL). Configured: $CURRENT. Inspect: journalctl -u $UNIT"
