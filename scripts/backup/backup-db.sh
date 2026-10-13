#!/usr/bin/env bash
#
# Backup database EduCore (PRD-000 §9: pg_dump harian). Untuk server Linux (production).
#
# Pemakaian:
#   scripts/backup/backup-db.sh
#
# Pengaturan lewat variabel lingkungan (semuanya opsional):
#   APP_DIR         folder aplikasi (default: dua tingkat di atas script ini)
#   BACKUP_DIR      folder tujuan backup (default: /var/backups/educore)
#   RETENTION_DAYS  berapa hari file backup disimpan (default: 14)
#
# Kredensial database dibaca dari .env aplikasi (DB_HOST, DB_PORT, DB_DATABASE,
# DB_USERNAME, DB_PASSWORD). Password tidak pernah ditampilkan di layar atau log.
#
# Contoh cron (setiap hari 01:30, sebagai user aplikasi):
#   30 1 * * * /var/www/educore/scripts/backup/backup-db.sh >> /var/log/educore/backup.log 2>&1

set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
APP_DIR="${APP_DIR:-$(cd "$SCRIPT_DIR/../.." && pwd)}"
BACKUP_DIR="${BACKUP_DIR:-/var/backups/educore}"
RETENTION_DAYS="${RETENTION_DAYS:-14}"
ENV_FILE="$APP_DIR/.env"

log() { echo "[$(date '+%Y-%m-%d %H:%M:%S')] $*"; }
fail() { log "GAGAL: $*" >&2; exit 1; }

[[ -f "$ENV_FILE" ]] || fail "File .env tidak ditemukan di $APP_DIR"
command -v pg_dump >/dev/null || fail "pg_dump tidak ditemukan. Pasang paket postgresql-client."
[[ "$RETENTION_DAYS" =~ ^[0-9]+$ ]] || fail "RETENTION_DAYS harus angka"

# Ambil nilai dari .env tanpa menjalankan isinya (lebih aman daripada `source .env`).
env_value() {
    local value
    value="$(grep -E "^$1=" "$ENV_FILE" | tail -n 1 | cut -d '=' -f 2- || true)"
    value="${value%$'\r'}"
    value="${value#\"}"; value="${value%\"}"
    value="${value#\'}"; value="${value%\'}"
    printf '%s' "$value"
}

DB_HOST="$(env_value DB_HOST)"
DB_PORT="$(env_value DB_PORT)"
DB_DATABASE="$(env_value DB_DATABASE)"
DB_USERNAME="$(env_value DB_USERNAME)"
export PGPASSWORD="$(env_value DB_PASSWORD)"

[[ -n "$DB_DATABASE" ]] || fail "DB_DATABASE kosong di .env"

umask 077
mkdir -p "$BACKUP_DIR"

STAMP="$(date '+%Y%m%d_%H%M%S')"
TARGET="$BACKUP_DIR/educore_${STAMP}.dump"
PARTIAL="$TARGET.partial"

# Hapus file setengah jadi bila proses berhenti di tengah jalan.
trap 'rm -f "$PARTIAL"' EXIT

log "Mulai backup database $DB_DATABASE"

pg_dump \
    --host="${DB_HOST:-127.0.0.1}" \
    --port="${DB_PORT:-5432}" \
    --username="${DB_USERNAME:-postgres}" \
    --no-password \
    --format=custom \
    --no-owner \
    --no-privileges \
    --file="$PARTIAL" \
    "$DB_DATABASE"

# Pastikan file bisa dibaca pg_restore sebelum dianggap jadi.
pg_restore --list "$PARTIAL" >/dev/null || fail "File backup rusak: $PARTIAL"

mv "$PARTIAL" "$TARGET"
trap - EXIT

SIZE="$(du -h "$TARGET" | cut -f 1)"
log "Backup selesai: $TARGET ($SIZE)"

# Rotasi: hapus backup yang lebih tua dari RETENTION_DAYS hari.
DELETED="$(find "$BACKUP_DIR" -maxdepth 1 -name 'educore_*.dump' -type f -mtime +"$RETENTION_DAYS" -print -delete | wc -l)"
log "Rotasi: $DELETED file lebih tua dari $RETENTION_DAYS hari dihapus"
