#!/usr/bin/env bash
# Daily SQLite backup, run by cron as the app user. Keeps KEEP_DAYS days locally
# and, when /etc/inbox/backup.env is filled in, sends an age-encrypted copy off
# the server with rclone.
# The database holds Gmail tokens encrypted with APP_KEY: a backup is only
# restorable with the same key, so keep a copy of APP_KEY outside the server.
set -euo pipefail

APP_DIR=$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)
DB_FILE=${DB_FILE:-${APP_DIR}/database/database.sqlite}
BACKUP_DIR=${BACKUP_DIR:-/var/backups/inbox}
BACKUP_CONFIG=${BACKUP_CONFIG:-/etc/inbox/backup.env}
KEEP_DAYS=${KEEP_DAYS:-14}

log() { printf '%s %s\n' "$(date -u +%FT%TZ)" "$*"; }

ping_heartbeat() { # suffix
    [[ -n "${BACKUP_HEARTBEAT_URL:-}" ]] || return 0
    curl -fsS -m 10 --retry 3 -o /dev/null "${BACKUP_HEARTBEAT_URL%/}$1" || log "aviso: o ping do heartbeat falhou"
}

on_error() {
    log "backup FALHOU (linha $1)"
    ping_heartbeat /fail
}

if [[ -r "$BACKUP_CONFIG" ]]; then
    set -a
    # shellcheck source=/dev/null
    . "$BACKUP_CONFIG"
    set +a
fi

trap 'on_error $LINENO' ERR
# sudo keeps the caller's directory (e.g. /root), which find can't return to.
cd "$BACKUP_DIR"
umask 077
upload=""
trap 'rm -f "$upload"' EXIT

name="db-$(date -u +%Y%m%d-%H%M%S).sqlite"
target="${BACKUP_DIR}/${name}"

# .backup is consistent even while the app writes (WAL); copying the file is not.
sqlite3 "$DB_FILE" ".backup '${target}'"
gzip "$target"
find "$BACKUP_DIR" -name 'db-*.sqlite.gz' -mtime +"$KEEP_DAYS" -delete
log "backup local ok: ${target}.gz"

if [[ -z "${BACKUP_AGE_RECIPIENT:-}" || -z "${BACKUP_REMOTE_PATH:-}" ]]; then
    # No success ping: a configured heartbeat must notice that nothing leaves the server.
    log "envio externo desativado: preencha ${BACKUP_CONFIG}"
    exit 0
fi

# Without the remote prefix rclone treats the value as a local path and
# "succeeds" without the copy ever leaving the server.
if [[ "$BACKUP_REMOTE_PATH" != offsite:?* ]]; then
    log "BACKUP_REMOTE_PATH deve começar com offsite: (ex.: offsite:<bucket>/db)"
    false
fi

upload=$(mktemp "${BACKUP_DIR}/.upload-XXXXXX")
age -r "$BACKUP_AGE_RECIPIENT" -o "$upload" "${target}.gz"
# The B2 key is write-only, so rclone must not list the destination first; the
# remote comes from RCLONE_CONFIG_OFFSITE_* (no config file).
rclone copyto --config "" --no-check-dest --retries 3 "$upload" "${BACKUP_REMOTE_PATH%/}/${name}.gz.age"
log "envio externo ok: ${name}.gz.age"
ping_heartbeat ""
