#!/usr/bin/env bash
# Daily SQLite backup, run by cron as the app user. Keeps KEEP_DAYS days.
# The database holds Gmail tokens encrypted with APP_KEY: a backup is only
# restorable with the same key, so keep a copy of APP_KEY outside the server.
set -euo pipefail

APP_DIR=$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)
DB_FILE=${DB_FILE:-${APP_DIR}/database/database.sqlite}
BACKUP_DIR=${BACKUP_DIR:-/var/backups/inbox}
KEEP_DAYS=${KEEP_DAYS:-14}

umask 077
target="${BACKUP_DIR}/db-$(date -u +%Y%m%d-%H%M%S).sqlite"

# .backup is consistent even while the app writes (WAL); copying the file is not.
sqlite3 "$DB_FILE" ".backup '${target}'"
gzip "$target"
find "$BACKUP_DIR" -name 'db-*.sqlite.gz' -mtime +"$KEEP_DAYS" -delete

printf '%s backup ok: %s.gz\n' "$(date -u +%FT%TZ)" "$target"
