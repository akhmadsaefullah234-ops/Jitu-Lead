#!/usr/bin/env bash
# JITU LEAD: first-time install on a fresh Ubuntu 22.04/24.04 (or Debian 12) VPS.
#
#   sudo bash install.sh app.contoh.com email@contoh.com [git-ref]
#
# Installs PHP 8.3, PostgreSQL, Nginx and HTTPS (Let's Encrypt), then the app,
# a queue worker and the scheduler. Run it as root. Safe to re-run: it keeps the
# existing database password and .env, and updates the code.
set -euo pipefail

DOMAIN="${1:-}"
EMAIL="${2:-}"
REF="${3:-main}"
APP_DIR=/var/www/jitu-lead
REPO=https://github.com/akhmadsaefullah234-ops/Jitu-Lead.git

[ "$(id -u)" -eq 0 ] || { echo "Jalankan sebagai root (sudo)."; exit 1; }
[ -n "$DOMAIN" ] && [ -n "$EMAIL" ] || { echo "Pemakaian: sudo bash install.sh domain.anda email@anda [git-ref]"; exit 1; }
[[ "$DOMAIN" =~ ^[A-Za-z0-9.-]+$ ]] || { echo "Domain tidak valid."; exit 1; }

# Another site on this machine would be broken by what follows; stop instead.
if ss -tln | grep -qE ':(80|443)\s' && ! systemctl is-active --quiet nginx; then
  echo "Port 80/443 sudah dipakai program selain Nginx. Hentikan dulu atau minta bantuan."; exit 1
fi

export DEBIAN_FRONTEND=noninteractive
apt-get update -y
apt-get install -y ca-certificates curl git unzip lsb-release gnupg software-properties-common nginx postgresql certbot python3-certbot-nginx

. /etc/os-release
if [ "$ID" = "ubuntu" ] && ! apt-cache show php8.3-fpm >/dev/null 2>&1; then
  add-apt-repository -y ppa:ondrej/php && apt-get update -y
elif [ "$ID" = "debian" ] && ! apt-cache show php8.3-fpm >/dev/null 2>&1; then
  curl -fsSL https://packages.sury.org/php/apt.gpg -o /etc/apt/trusted.gpg.d/php.gpg
  echo "deb https://packages.sury.org/php/ $VERSION_CODENAME main" > /etc/apt/sources.list.d/php.list && apt-get update -y
fi
apt-get install -y php8.3-fpm php8.3-cli php8.3-pgsql php8.3-mbstring php8.3-xml php8.3-curl php8.3-zip php8.3-intl php8.3-bcmath php8.3-gd

if ! command -v composer >/dev/null; then
  curl -fsSL https://getcomposer.org/installer -o /tmp/composer-setup.php
  php /tmp/composer-setup.php --install-dir=/usr/local/bin --filename=composer
  rm /tmp/composer-setup.php
fi

# Database: created once, password kept in .env afterwards.
if [ ! -f "$APP_DIR/.env" ]; then
  DB_PASS="$(openssl rand -hex 24)"
  sudo -u postgres psql -v ON_ERROR_STOP=1 <<SQL
DO \$\$ BEGIN
  IF NOT EXISTS (SELECT FROM pg_roles WHERE rolname = 'jitu') THEN CREATE ROLE jitu LOGIN PASSWORD '$DB_PASS'; END IF;
END \$\$;
SQL
  sudo -u postgres psql -tc "SELECT 1 FROM pg_database WHERE datname='jitu_lead'" | grep -q 1 || sudo -u postgres createdb -O jitu jitu_lead
  sudo -u postgres psql -c "ALTER ROLE jitu PASSWORD '$DB_PASS'"
fi

# Code
git config --global --add safe.directory "$APP_DIR"
if [ -d "$APP_DIR/.git" ]; then
  git -C "$APP_DIR" fetch origin "$REF" && git -C "$APP_DIR" checkout -q FETCH_HEAD
else
  git clone "$REPO" "$APP_DIR" && git -C "$APP_DIR" fetch origin "$REF" && git -C "$APP_DIR" checkout -q FETCH_HEAD
fi
cd "$APP_DIR"

if [ ! -f .env ]; then
  cp .env.example .env
  sed -i "s|^APP_ENV=.*|APP_ENV=production|; s|^APP_DEBUG=.*|APP_DEBUG=false|; s|^APP_URL=.*|APP_URL=https://$DOMAIN|" .env
  sed -i "s|^DB_PASSWORD=.*|DB_PASSWORD=$DB_PASS|; s|^LOG_LEVEL=.*|LOG_LEVEL=warning|" .env
  grep -q '^SESSION_SECURE_COOKIE' .env || echo 'SESSION_SECURE_COOKIE=true' >> .env
fi

chown -R www-data:www-data "$APP_DIR"
as_www() { sudo -u www-data -H "$@"; }
as_www composer install --no-dev --optimize-autoloader --no-interaction
grep -q '^APP_KEY=.\+' .env || as_www php artisan key:generate --force
as_www php artisan migrate --force
as_www php artisan filament:assets 2>/dev/null || true
as_www php artisan storage:link 2>/dev/null || true
as_www php artisan optimize
chmod 640 .env

# Nginx. Public form and landing pages must be embeddable on other sites, so
# only the rest of the app is protected from framing.
cat > /etc/nginx/conf.d/jitu-lead-frames.conf <<'MAP'
map $uri $jitu_xfo {
    ~^/(f|p)/ "";
    default   "SAMEORIGIN";
}
MAP
cat > /etc/nginx/sites-available/jitu-lead <<NGINX
server {
    listen 80;
    server_name $DOMAIN;
    root $APP_DIR/public;
    index index.php;
    client_max_body_size 10M;
    add_header X-Frame-Options \$jitu_xfo always;
    add_header X-Content-Type-Options "nosniff" always;
    location / { try_files \$uri \$uri/ /index.php?\$query_string; }
    location ~ \.php\$ {
        include snippets/fastcgi-php.conf;
        fastcgi_pass unix:/run/php/php8.3-fpm.sock;
    }
    location ~ /\.(?!well-known) { deny all; }
}
NGINX
ln -sf /etc/nginx/sites-available/jitu-lead /etc/nginx/sites-enabled/jitu-lead
rm -f /etc/nginx/sites-enabled/default
nginx -t && systemctl reload nginx

# Queue worker and scheduler
cat > /etc/systemd/system/jitu-queue.service <<UNIT
[Unit]
Description=JITU LEAD queue worker
After=network.target postgresql.service

[Service]
User=www-data
WorkingDirectory=$APP_DIR
ExecStart=/usr/bin/php artisan queue:work --sleep=3 --tries=3 --max-time=3600
Restart=always
RestartSec=5

[Install]
WantedBy=multi-user.target
UNIT
systemctl daemon-reload && systemctl enable --now jitu-queue
echo "* * * * * www-data cd $APP_DIR && php artisan schedule:run >> /dev/null 2>&1" > /etc/cron.d/jitu-lead

# HTTPS (needs the domain's DNS A record to point at this server already)
if certbot --nginx -d "$DOMAIN" -m "$EMAIL" --agree-tos --no-eff-email --redirect -n; then
  echo "HTTPS aktif."
else
  echo "PERINGATAN: HTTPS gagal. Pastikan DNS $DOMAIN mengarah ke IP server ini, lalu jalankan ulang skrip."
fi

if command -v ufw >/dev/null && ufw status | grep -q active; then ufw allow 'Nginx Full' >/dev/null; fi

echo
echo "Selesai. Buka https://$DOMAIN/app dan daftar akun admin pertama."
