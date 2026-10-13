#!/usr/bin/env bash
#
# Uji restore backup EduCore ke database SEMENTARA (PRD-000 §9: uji restore bulanan).
# Database asli tidak disentuh sama sekali.
#
# Pemakaian:
#   scripts/backup/restore-test.sh                 # pakai backup terbaru di BACKUP_DIR
#   scripts/backup/restore-test.sh /path/file.dump # pakai file tertentu
#
# Butuh user database yang boleh membuat database (CREATEDB). Hasilnya ditulis ke layar;
# catat di docs/operations/BACKUP-RESTORE.md bagian "Catatan uji restore".
#
# Contoh cron (tanggal 1 setiap bulan, 03:00):
#   0 3 1 * * /var/www/educore/scripts/backup/restore-test.sh >> /var/log/educore/restore-test.log 2>&1

set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
APP_DIR="${APP_DIR:-$(cd "$SCRIPT_DIR/../.." && pwd)}"
BACKUP_DIR="${BACKUP_DIR:-/var/backups/educore}"
ENV_FILE="$APP_DIR/.env"
TEST_DB="educore_restore_test"

log() { echo "[$(date '+%Y-%m-%d %H:%M:%S')] $*"; }
fail() { log "GAGAL: $*" >&2; exit 1; }

[[ -f "$ENV_FILE" ]] || fail "File .env tidak ditemukan di $APP_DIR"

env_value() {
    local value
    value="$(grep -E "^$1=" "$ENV_FILE" | tail -n 1 | cut -d '=' -f 2- || true)"
    value="${value%$'\r'}"
    value="${value#\"}"; value="${value%\"}"
    value="${value#\'}"; value="${value%\'}"
    printf '%s' "$value"
}

export PGHOST="$(env_value DB_HOST)"
export PGPORT="$(env_value DB_PORT)"
export PGUSER="$(env_value DB_USERNAME)"
export PGPASSWORD="$(env_value DB_PASSWORD)"
PGHOST="${PGHOST:-127.0.0.1}"; PGPORT="${PGPORT:-5432}"; PGUSER="${PGUSER:-postgres}"
LIVE_DB="$(env_value DB_DATABASE)"

[[ "$LIVE_DB" != "$TEST_DB" ]] || fail "DB_DATABASE tidak boleh sama dengan database uji"

DUMP="${1:-$(ls -1t "$BACKUP_DIR"/educore_*.dump 2>/dev/null | head -n 1 || true)}"
[[ -n "$DUMP" && -f "$DUMP" ]] || fail "File backup tidak ditemukan (BACKUP_DIR=$BACKUP_DIR)"

cleanup() { dropdb --no-password --if-exists "$TEST_DB" >/dev/null 2>&1 || true; }
trap cleanup EXIT

log "Uji restore dari $DUMP"
cleanup
createdb --no-password "$TEST_DB"

START=$(date +%s)
pg_restore --no-password --no-owner --no-privileges --exit-on-error --dbname="$TEST_DB" "$DUMP"
DURATION=$(( $(date +%s) - START ))

count() { psql -X --no-password --dbname="$TEST_DB" --tuples-only --no-align --command="SELECT count(*) FROM $1"; }

TENANTS="$(count tenants)"
ORGANIZATIONS="$(count organizations)"
USERS="$(count users)"
AUDIT="$(count audit_logs)"

[[ "$TENANTS" -gt 0 ]] || fail "Hasil restore tidak berisi yayasan"

log "BERHASIL dalam ${DURATION} detik: tenants=$TENANTS, organizations=$ORGANIZATIONS, users=$USERS, audit_logs=$AUDIT"
log "Database uji $TEST_DB dihapus kembali."
