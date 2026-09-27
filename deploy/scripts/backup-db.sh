#!/usr/bin/env bash
# Famboook Pilot — daily PostgreSQL backup TEMPLATE (docs/08 §12).
# Credentials come from ~/.pgpass (chmod 600) of the backup user — never
# from this file. Encrypts with the backup public key (age or gpg) so the
# backup host never holds a readable copy.
#
# cron (as the backup user):  15 2 * * *  /opt/famboook/backup-db.sh >> /var/log/famboook-backup.log 2>&1
set -euo pipefail
umask 077

DB_NAME="famboook_pilot"
DB_USER="famboook_backup"          # read-only role, or the app role
DB_HOST="127.0.0.1"
BACKUP_DIR="/var/backups/famboook" # NOT inside the PostgreSQL data directory
RECIPIENT="<age-public-key-or-gpg-key-id>"
RETENTION_DAYS=30

stamp="$(date -u +%Y%m%dT%H%M%SZ)"
file="${BACKUP_DIR}/famboook_pilot_${stamp}.dump"

mkdir -p "$BACKUP_DIR"

# Custom format: compressed, restorable with pg_restore, table-selective.
pg_dump --host="$DB_HOST" --username="$DB_USER" --format=custom --no-owner --no-privileges \
  --file="$file" "$DB_NAME"

# Refuse an unreadable dump before encrypting it.
pg_restore --list "$file" > /dev/null

age --recipient "$RECIPIENT" --output "${file}.age" "$file"   # or: gpg --encrypt --recipient "$RECIPIENT" "$file"
shred -u "$file" 2>/dev/null || rm -f "$file"
sha256sum "${file}.age" > "${file}.age.sha256"

# Copy off the server (placeholder): rsync/rclone to restricted storage.
# Owner-approved retention (docs/08 §2a/§12): 30 daily + 12 monthly, enforced
# on the off-server storage (lifecycle rules or a prune job there).
# rclone copy "${file}.age" "<remote>:famboook-backups/daily/" && rclone copy "${file}.age.sha256" "<remote>:famboook-backups/daily/"
# if [[ "$(date -u +%d)" == "01" ]]; then
#   rclone copy "${file}.age" "<remote>:famboook-backups/monthly/" && rclone copy "${file}.age.sha256" "<remote>:famboook-backups/monthly/"
# fi

# Local copies are a short convenience cache only; retention lives off-server.
find "$BACKUP_DIR" -name 'famboook_pilot_*.dump.age*' -mtime +"$RETENTION_DAYS" -delete
echo "backup ok: ${file}.age"
