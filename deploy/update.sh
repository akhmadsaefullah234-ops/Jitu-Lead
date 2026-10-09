#!/usr/bin/env bash
# JITU LEAD: update the live app with a safety net.
#
#   sudo bash deploy/update.sh [git-ref] [-y]
#
# Steps: back up the database -> show the maintenance page ("sedang diperbarui")
# -> pull the code, install, migrate -> check the app answers -> reopen.
# If anything fails, the previous code is put back and the site is reopened.
# Database changes are not undone automatically (see docs/deploy.md for why that
# is usually unnecessary and how to restore the backup if you do need it).
#
# Try the new version first with deploy/staging.sh. Not yet run on a real server.
set -Eeuo pipefail

REF="main"
ASSUME_YES=0
for arg in "$@"; do
  case "$arg" in
    -y|--yes) ASSUME_YES=1 ;;
    -*) echo "Opsi tidak dikenal: $arg"; exit 1 ;;
    *) REF="$arg" ;;
  esac
done

APP_DIR="${APP_DIR:-/var/www/jitu-lead}"
STG_DIR="${STG_DIR:-/var/www/jitu-lead-staging}"
BACKUP_DIR="${BACKUP_DIR:-/var/backups/jitu-lead}"
KEEP_BACKUPS=14

[ "$(id -u)" -eq 0 ] || { echo "Jalankan sebagai root (sudo)."; exit 1; }
[ -d "$APP_DIR/.git" ] && [ -f "$APP_DIR/.env" ] || { echo "Aplikasi tidak ditemukan di $APP_DIR. Pasang dulu dengan install.sh."; exit 1; }
cd "$APP_DIR"

as_www() { sudo -u www-data -H "$@"; }
env_get() { grep -E "^$1=" .env | head -1 | cut -d= -f2- | sed 's/^"//; s/"$//'; }
log() { printf '\n==> %s\n' "$*"; }

PREV="$(git rev-parse HEAD)"
git fetch -q origin "$REF"
TARGET="$(git rev-parse FETCH_HEAD)"

if [ "$PREV" = "$TARGET" ]; then
  echo "Sudah di versi terbaru ($PREV). Tidak ada yang diperbarui."; exit 0
fi

echo "Versi sekarang : ${PREV:0:10}"
echo "Versi baru     : ${TARGET:0:10} ($REF)"
git --no-pager log --oneline "$PREV..$TARGET" 2>/dev/null | head -15 || true

# Was this exact version already tried on the staging copy?
if [ -d "$STG_DIR/.git" ]; then
  STG="$(git -C "$STG_DIR" rev-parse HEAD 2>/dev/null || true)"
  if [ "$STG" != "$TARGET" ]; then
    echo "PERINGATAN: versi ini belum dicoba di staging (staging ada di ${STG:0:10})."
    echo "Coba dulu: sudo bash deploy/staging.sh <domain-staging> <email> $REF"
  else
    echo "Versi ini sudah dicoba di staging."
  fi
else
  echo "Staging belum dipasang (deploy/staging.sh). Lanjut tanpa uji coba."
fi

if [ "$ASSUME_YES" -ne 1 ]; then
  read -r -p "Lanjut memperbarui sekarang? [y/N] " ans
  [[ "$ans" =~ ^[Yy]$ ]] || { echo "Dibatalkan."; exit 1; }
fi

# --- 1. database backup (before anything changes) ---
log "Mencadangkan database"
mkdir -p "$BACKUP_DIR" && chmod 700 "$BACKUP_DIR"
STAMP="$(date +%Y%m%d-%H%M%S)"
BACKUP="$BACKUP_DIR/db-$STAMP-${PREV:0:8}.sql.gz"
PGPASSWORD="$(env_get DB_PASSWORD)" pg_dump -h "$(env_get DB_HOST)" -p "$(env_get DB_PORT)" -U "$(env_get DB_USERNAME)" "$(env_get DB_DATABASE)" | gzip > "$BACKUP"
[ -s "$BACKUP" ] || { echo "Cadangan kosong, dibatalkan. Tidak ada yang diubah."; exit 1; }
echo "Cadangan: $BACKUP"
# shellcheck disable=SC2012
ls -1t "$BACKUP_DIR"/db-*.sql.gz 2>/dev/null | tail -n +$((KEEP_BACKUPS + 1)) | xargs -r rm -f

# --- 2. maintenance page, with a secret link so you can still look around ---
SECRET="$(openssl rand -hex 8)"
DOWN=0
rollback() {
  local code=$?
  trap - ERR
  echo
  echo "!!! Pembaruan gagal (kode $code). Mengembalikan versi sebelumnya ${PREV:0:10}."
  git checkout -q "$PREV" || true
  as_www composer install --no-dev --optimize-autoloader --no-interaction -q || true
  as_www php artisan optimize -q || true
  as_www php artisan queue:restart || true
  as_www php artisan up || true
  DOWN=0
  echo "Situs dibuka kembali dengan versi lama."
  echo "Cadangan database sebelum pembaruan: $BACKUP"
  echo "Migrasi yang sudah selesai TIDAK dibatalkan otomatis (biasanya aman, lihat docs/deploy.md)."
  exit "$code"
}
trap rollback ERR
trap '[ "$DOWN" -eq 1 ] && as_www php artisan up || true' EXIT

log "Menampilkan halaman pemeliharaan"
as_www php artisan down --retry=30 --refresh=15 --secret="$SECRET"
DOWN=1

# --- 3. new code ---
log "Mengambil kode $REF"
git checkout -q "$TARGET"
chown -R www-data:www-data "$APP_DIR"
as_www composer install --no-dev --optimize-autoloader --no-interaction
as_www php artisan migrate --force
as_www php artisan filament:assets 2>/dev/null || true
as_www php artisan optimize

# --- 4. does it answer? (through the secret link, while visitors still see the notice) ---
log "Memeriksa aplikasi"
DOMAIN="$(env_get APP_URL | sed 's|^[a-z]*://||; s|/.*||')"
JAR="$(mktemp)"
trap 'rm -f "$JAR"; rollback' ERR
curl -fsS -o /dev/null -c "$JAR" -L --resolve "$DOMAIN:443:127.0.0.1" --max-time 30 "https://$DOMAIN/$SECRET"
for path in / /app/login; do
  CODE="$(curl -sS -o /dev/null -w '%{http_code}' -b "$JAR" --resolve "$DOMAIN:443:127.0.0.1" --max-time 30 "https://$DOMAIN$path")"
  echo "  $path -> $CODE"
  [ "$CODE" = "200" ] || { echo "Halaman $path tidak normal."; false; }
done
rm -f "$JAR"
trap rollback ERR

# --- 5. reopen; workers finish their current job, then restart on the new code ---
log "Membuka kembali situs"
as_www php artisan queue:restart
as_www php artisan up
DOWN=0
trap - ERR

echo
echo "Selesai. Sekarang di versi ${TARGET:0:10}."
echo "Cadangan: $BACKUP (disimpan $KEEP_BACKUPS terakhir)."
