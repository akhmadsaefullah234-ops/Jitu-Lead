#!/usr/bin/env bash
# JITU LEAD: a preview ("staging") copy of the app on the same VPS, so a new
# version can be tried without touching what customers use.
#
#   sudo bash deploy/staging.sh staging.contoh.com email@contoh.com [git-ref] [--copy-data] [--basic-auth user:password]
#
# - Its own folder, database, address and login cookie. Production is not touched.
# - Emails go to the log, no queue worker or scheduler runs, AI and WhatsApp keys
#   are empty, ad pixels are off: nothing real is sent from staging.
# - Search engines are told to stay away (noindex).
# - Without --copy-data the database starts empty (use "Isi data contoh" in the app).
#   With --copy-data, production data is copied in (and migrated with the new
#   code, so you see how the update behaves on real data). Because that holds real
#   customers' data, --basic-auth is then required, and WhatsApp/tracking secrets
#   and queued jobs are removed from the copy.
# - Re-running keeps the staging data and only updates the code and migrates,
#   unless --copy-data is given again (that replaces the staging data).
#
# Once it looks good: sudo bash deploy/update.sh <git-ref>
# Not yet run on a real server.
set -Eeuo pipefail

DOMAIN=""; EMAIL=""; REF="main"; COPY_DATA=0; BASIC_AUTH=""
POS=0
while [ $# -gt 0 ]; do
  case "$1" in
    --copy-data) COPY_DATA=1 ;;
    --basic-auth) shift; BASIC_AUTH="${1:-}" ;;
    -*) echo "Opsi tidak dikenal: $1"; exit 1 ;;
    *) POS=$((POS + 1)); case "$POS" in 1) DOMAIN="$1" ;; 2) EMAIL="$1" ;; 3) REF="$1" ;; esac ;;
  esac
  shift
done

APP_DIR="${APP_DIR:-/var/www/jitu-lead}"
STG_DIR="${STG_DIR:-/var/www/jitu-lead-staging}"
REPO=https://github.com/akhmadsaefullah234-ops/Jitu-Lead.git
STG_DB=jitu_lead_staging
STG_USER=jitu_stg

[ "$(id -u)" -eq 0 ] || { echo "Jalankan sebagai root (sudo)."; exit 1; }
[ -n "$DOMAIN" ] && [ -n "$EMAIL" ] || { echo "Pemakaian: sudo bash staging.sh staging.domain email@anda [git-ref] [--copy-data] [--basic-auth user:sandi]"; exit 1; }
[[ "$DOMAIN" =~ ^[A-Za-z0-9.-]+$ ]] || { echo "Domain tidak valid."; exit 1; }
[ -f "$APP_DIR/.env" ] || { echo "Produksi belum terpasang di $APP_DIR (jalankan install.sh dulu)."; exit 1; }
[ "$(grep -E '^APP_URL=' "$APP_DIR/.env" | head -1 | sed 's|^APP_URL=.*://||; s|/.*||')" != "$DOMAIN" ] || { echo "Domain staging harus berbeda dari domain produksi."; exit 1; }
if [ "$COPY_DATA" -eq 1 ] && [ -z "$BASIC_AUTH" ]; then
  echo "--copy-data menyalin data pelanggan asli, jadi wajib dengan --basic-auth user:sandi agar staging tidak terbuka untuk umum."; exit 1
fi
if [ -n "$BASIC_AUTH" ] && ! [[ "$BASIC_AUTH" =~ ^[A-Za-z0-9._-]+:.{8,}$ ]]; then
  echo "--basic-auth harus berbentuk user:sandi (sandi minimal 8 karakter)."; exit 1
fi

as_www() { sudo -u www-data -H "$@"; }
env_get() { grep -E "^$1=" "$2" | head -1 | cut -d= -f2- | sed 's/^"//; s/"$//'; }
set_env() { # file key value
  if grep -qE "^$2=" "$1"; then sed -i "s|^$2=.*|$2=$3|" "$1"; else echo "$2=$3" >> "$1"; fi
}
log() { printf '\n==> %s\n' "$*"; }

export DEBIAN_FRONTEND=noninteractive
[ -z "$BASIC_AUTH" ] || apt-get install -y apache2-utils >/dev/null

# --- database (own role and database; the password stays in the staging .env) ---
FRESH=0
if [ ! -f "$STG_DIR/.env" ]; then
  FRESH=1
  STG_PASS="$(openssl rand -hex 24)"
  sudo -u postgres psql -v ON_ERROR_STOP=1 <<SQL
DO \$\$ BEGIN
  IF NOT EXISTS (SELECT FROM pg_roles WHERE rolname = '$STG_USER') THEN CREATE ROLE $STG_USER LOGIN PASSWORD '$STG_PASS'; END IF;
END \$\$;
SQL
  sudo -u postgres psql -c "ALTER ROLE $STG_USER PASSWORD '$STG_PASS'"
  sudo -u postgres psql -tc "SELECT 1 FROM pg_database WHERE datname='$STG_DB'" | grep -q 1 || sudo -u postgres createdb -O "$STG_USER" "$STG_DB"
fi

# --- code ---
log "Mengambil kode $REF"
git config --global --add safe.directory "$STG_DIR"
if [ -d "$STG_DIR/.git" ]; then
  git -C "$STG_DIR" fetch -q origin "$REF" && git -C "$STG_DIR" checkout -q -f FETCH_HEAD
else
  git clone -q "$REPO" "$STG_DIR" && git -C "$STG_DIR" fetch -q origin "$REF" && git -C "$STG_DIR" checkout -q FETCH_HEAD
fi
cd "$STG_DIR"

if [ "$FRESH" -eq 1 ]; then
  cp .env.example .env
  set_env .env APP_ENV staging
  set_env .env APP_DEBUG false
  set_env .env APP_URL "https://$DOMAIN"
  set_env .env DB_DATABASE "$STG_DB"
  set_env .env DB_USERNAME "$STG_USER"
  set_env .env DB_PASSWORD "$STG_PASS"
  set_env .env LOG_LEVEL warning
  set_env .env SESSION_COOKIE jitu_staging_session
  set_env .env SESSION_SECURE_COOKIE true
  set_env .env MAIL_MAILER log
  set_env .env QUEUE_CONNECTION sync
  set_env .env REGISTRATION_MODE open
  set_env .env ANTHROPIC_API_KEY ""
  set_env .env SUPPORT_EMAIL ""
fi
chown -R www-data:www-data "$STG_DIR"
as_www composer install --no-dev --optimize-autoloader --no-interaction
grep -q '^APP_KEY=.\+' .env || as_www php artisan key:generate --force

# --- data ---
if [ "$COPY_DATA" -eq 1 ]; then
  log "Menyalin data produksi ke staging (data staging lama diganti)"
  PROD_ENV="$APP_DIR/.env"
  PGPASSWORD="$(env_get DB_PASSWORD "$PROD_ENV")" pg_dump --no-owner --no-privileges -h "$(env_get DB_HOST "$PROD_ENV")" -p "$(env_get DB_PORT "$PROD_ENV")" -U "$(env_get DB_USERNAME "$PROD_ENV")" "$(env_get DB_DATABASE "$PROD_ENV")" > /tmp/jitu-staging-copy.sql
  chmod 600 /tmp/jitu-staging-copy.sql
  SP="$(env_get DB_PASSWORD .env)"
  PGPASSWORD="$SP" psql -q -h 127.0.0.1 -U "$STG_USER" "$STG_DB" -v ON_ERROR_STOP=1 -c 'DROP SCHEMA public CASCADE; CREATE SCHEMA public;'
  PGPASSWORD="$SP" psql -q -h 127.0.0.1 -U "$STG_USER" "$STG_DB" -v ON_ERROR_STOP=1 -f /tmp/jitu-staging-copy.sql
  rm -f /tmp/jitu-staging-copy.sql
  # Nothing real may leave from staging: drop secrets, ad pixels and anything queued.
  PGPASSWORD="$SP" psql -q -h 127.0.0.1 -U "$STG_USER" "$STG_DB" -v ON_ERROR_STOP=1 <<'SQL'
UPDATE wa_channels SET credentials = NULL, status = 'disconnected';
UPDATE tracking_settings SET credentials = NULL, meta_pixel_id = NULL, tiktok_pixel_id = NULL, google_tag_id = NULL, google_ads_label = NULL;
TRUNCATE jobs, failed_jobs, sessions;
SQL
fi
log "Migrasi database staging"
as_www php artisan migrate --force
as_www php artisan filament:assets 2>/dev/null || true
as_www php artisan storage:link 2>/dev/null || true
as_www php artisan optimize
chmod 640 .env

# --- nginx (shares the frame-header map that install.sh created) ---
AUTH_LINES=""
if [ -n "$BASIC_AUTH" ]; then
  htpasswd -bc /etc/nginx/jitu-staging.htpasswd "${BASIC_AUTH%%:*}" "${BASIC_AUTH#*:}" >/dev/null
  chmod 640 /etc/nginx/jitu-staging.htpasswd && chgrp www-data /etc/nginx/jitu-staging.htpasswd
  AUTH_LINES='auth_basic "JITU LEAD staging"; auth_basic_user_file /etc/nginx/jitu-staging.htpasswd;'
fi
[ -f /etc/nginx/conf.d/jitu-lead-frames.conf ] || { echo "install.sh belum dijalankan (map frame tidak ada)."; exit 1; }
cat > /etc/nginx/sites-available/jitu-lead-staging <<NGINX
server {
    listen 80;
    server_name $DOMAIN;
    root $STG_DIR/public;
    index index.php;
    client_max_body_size 10M;
    $AUTH_LINES
    add_header X-Frame-Options \$jitu_xfo always;
    add_header X-Content-Type-Options "nosniff" always;
    add_header X-Robots-Tag "noindex, nofollow" always;
    location / { try_files \$uri \$uri/ /index.php?\$query_string; }
    location ~ \.php\$ {
        include snippets/fastcgi-php.conf;
        fastcgi_pass unix:/run/php/php8.3-fpm.sock;
    }
    location ~ /\.(?!well-known) { deny all; }
}
NGINX
ln -sf /etc/nginx/sites-available/jitu-lead-staging /etc/nginx/sites-enabled/jitu-lead-staging
nginx -t && systemctl reload nginx

if certbot --nginx -d "$DOMAIN" -m "$EMAIL" --agree-tos --no-eff-email --redirect -n; then
  echo "HTTPS aktif."
else
  echo "PERINGATAN: HTTPS gagal. Pastikan DNS $DOMAIN mengarah ke IP server ini, lalu jalankan ulang skrip."
fi

echo
echo "Staging siap: https://$DOMAIN/app  (versi $(git rev-parse --short HEAD), $REF)"
[ "$COPY_DATA" -eq 1 ] && echo "Berisi salinan data produksi. Login dengan akun produksi. Tidak ada email/WhatsApp/AI yang terkirim dari sini."
[ "$COPY_DATA" -eq 0 ] && echo "Database kosong: daftar akun baru lalu pakai tombol 'Isi data contoh' di dasbor."
echo "Jika sudah sesuai: sudo bash deploy/update.sh $REF"
