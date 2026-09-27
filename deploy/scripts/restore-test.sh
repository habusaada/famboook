#!/usr/bin/env bash
# Famboook Pilot — restore TEST into a TEMPORARY database (docs/08 §13).
# Never restores over the live database. Run as a PostgreSQL role that may
# create databases (the DB administrator), with credentials from ~/.pgpass.
#
#   ./restore-test.sh /var/backups/famboook/famboook_pilot_<stamp>.dump.age
set -euo pipefail
umask 077

ENCRYPTED="${1:?usage: restore-test.sh <backup.dump.age>}"
LIVE_DB="famboook_pilot"
TEMP_DB="famboook_restore_test_$(date -u +%Y%m%d%H%M%S)"
PGHOST="127.0.0.1"; export PGHOST
IDENTITY="<path-to-age-private-key>"   # kept offline / on the restore host only

[[ "$TEMP_DB" != "$LIVE_DB" ]] || { echo "refusing: temp name equals live DB"; exit 1; }

sha256sum --check "${ENCRYPTED}.sha256"
plain="$(mktemp)"; trap 'shred -u "$plain" 2>/dev/null || rm -f "$plain"; dropdb --if-exists "$TEMP_DB"' EXIT
age --decrypt --identity "$IDENTITY" --output "$plain" "$ENCRYPTED"

createdb "$TEMP_DB"
psql -d "$TEMP_DB" -c 'CREATE EXTENSION IF NOT EXISTS pg_trgm;'
pg_restore --dbname="$TEMP_DB" --no-owner --no-privileges --exit-on-error "$plain"

echo "== integrity checks (counts only, no personal data) =="
psql -d "$TEMP_DB" -v ON_ERROR_STOP=1 -At <<'SQL'
SELECT 'migrations='          || count(*) FROM migrations;
SELECT 'roles='               || count(*) FROM roles;
SELECT 'permissions='         || count(*) FROM permissions;
SELECT 'users='               || count(*) FROM users;
SELECT 'families='            || count(*) FROM families;
SELECT 'persons='             || count(*) FROM persons;
SELECT 'active_memberships='  || count(*) FROM family_memberships WHERE is_active;
SELECT 'activities='          || count(*) FROM family_activities;
-- invariants that must hold in any valid backup (each must be 0)
SELECT 'persons_with_2_active_memberships=' || count(*) FROM (SELECT person_id FROM family_memberships WHERE is_active GROUP BY person_id HAVING count(*) > 1) x;
SELECT 'families_with_2_active_heads='      || count(*) FROM (SELECT family_id FROM family_memberships WHERE is_active AND is_household_head GROUP BY family_id HAVING count(*) > 1) x;
SELECT 'families_with_2_current_residences='|| count(*) FROM (SELECT family_id FROM family_residences WHERE is_current GROUP BY family_id HAVING count(*) > 1) x;
SELECT 'pg_trgm_indexes='     || count(*) FROM pg_indexes WHERE indexname LIKE '%_trgm';
SQL

echo "Compare the counts with the live database (same queries, run read-only)."
echo "Encrypted Assistance snapshots additionally need the SAME APP_KEY to read (docs/08 §14)."
echo "Temporary database ${TEMP_DB} is dropped on exit."
