#!/usr/bin/env bash
# Famboook — notification health check (FB-NOTIFY-01E.1 TEMPLATE). READ-ONLY.
#
# Classifies the notification subsystem for an operator or a monitoring agent.
# It changes nothing: no restart, no dispatch, no database write. It sends
# nothing anywhere — wiring its exit code to an alert destination is a
# separate, approved step (checklist §8, decision D6).
#
#   ./notifications-health.sh                   run as the deploy user or www-data
#   PHP_BIN=/usr/bin/php8.3 ./notifications-health.sh
#
# STATE (last line) and exit code:
#   NOT_ACTIVATED  0  NOTIFICATIONS_MODE is OFF and famboook:notifications-check
#                     reports no warning. The worker state is informational:
#                     a stopped or absent worker is EXPECTED while OFF.
#   HEALTHY        0  PILOT or ON, no warning, and the worker unit is active
#                     (or systemctl is unavailable here — then the check's
#                     FRESH heartbeat is the proof that a worker runs).
#   DEGRADED       1  any warning of famboook:notifications-check (any mode:
#                     invalid configuration, stale PUBLISHING, PILOT / ON not
#                     ready — e.g. HEARTBEAT_STALE / ABSENT when the worker or
#                     the scheduler stopped), or PILOT / ON with the worker
#                     unit not active.
#   UNAVAILABLE    2  famboook:notifications-check could not run (PHP, the
#                     application or the database failed): nothing is known.
#
# Not covered: messages in PUBLISH_FAILED are not counted by the check (they
# are visible to their sender; transient publication errors are logged as
# "Notification publication failed." in backend/storage/logs).
set -uo pipefail

PHP_BIN="${PHP_BIN:-php}"
SYSTEMCTL="${SYSTEMCTL:-systemctl}"
UNIT="famboook-notifications-worker"

cd "$(dirname "$0")/../../backend" || { echo "STATE: UNAVAILABLE (backend directory not found)"; exit 2; }

echo "== famboook:notifications-check (counts and codes only)"
output="$("$PHP_BIN" artisan famboook:notifications-check --no-interaction 2>&1)"
check=$?
printf '%s\n' "$output"

# The check exits 0 (no warning) or 1 (warnings); anything else, or no mode
# line, means it did not complete.
mode="$(printf '%s\n' "$output" | sed -n 's/^Notification mode (effective): \([A-Z]*\)$/\1/p' | head -n 1)"
if [[ ( "$check" -ne 0 && "$check" -ne 1 ) || -z "$mode" ]]; then
  echo
  echo "STATE: UNAVAILABLE (famboook:notifications-check did not complete, exit ${check})"
  exit 2
fi

echo
echo "== ${UNIT}"
worker="unknown"
if command -v "$SYSTEMCTL" >/dev/null 2>&1; then
  worker="$("$SYSTEMCTL" is-active "$UNIT" 2>/dev/null || true)"
  worker="${worker:-unknown}"
  echo "systemd state: ${worker}"
else
  echo "systemd state: unknown (systemctl is not available on this host)"
fi

echo
if [[ "$mode" == "OFF" ]]; then
  if [[ "$check" -eq 1 ]]; then
    echo "STATE: DEGRADED (notifications are OFF, but the check reports warnings)"
    exit 1
  fi
  echo "STATE: NOT_ACTIVATED (NOTIFICATIONS_MODE=OFF; worker ${worker} — expected while OFF)"
  exit 0
fi

if [[ "$check" -eq 1 ]]; then
  echo "STATE: DEGRADED (mode ${mode}; the check reports warnings)"
  exit 1
fi
if [[ "$worker" != "active" && "$worker" != "unknown" ]]; then
  echo "STATE: DEGRADED (mode ${mode}; ${UNIT} is ${worker})"
  exit 1
fi
echo "STATE: HEALTHY (mode ${mode}; worker ${worker})"
exit 0
