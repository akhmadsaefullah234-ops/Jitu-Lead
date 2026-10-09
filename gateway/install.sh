#!/usr/bin/env bash
# Memasang gateway WhatsApp (scan QR) di VPS yang sama dengan CRM.
# Pakai:  sudo bash gateway/install.sh
# Aman dijalankan ulang: kunci lama dipertahankan, kode diperbarui.
set -euo pipefail

[ "$(id -u)" -eq 0 ] || { echo "Jalankan sebagai root (sudo)."; exit 1; }

APP_DIR=/opt/jitu-gateway
ENV_FILE=/etc/jitu-gateway.env
CRM_DIR=${CRM_DIR:-/var/www/jitu-lead}
SRC_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"

command -v node >/dev/null || { echo "Node.js belum terpasang. Pasang Node.js 20 atau lebih baru dulu (https://nodejs.org)."; exit 1; }
[ "$(node -p 'process.versions.node.split(".")[0]')" -ge 20 ] || { echo "Butuh Node.js 20 atau lebih baru. Terpasang: $(node -v)"; exit 1; }

id jitu-gw >/dev/null 2>&1 || useradd --system --home "$APP_DIR" --shell /usr/sbin/nologin jitu-gw

mkdir -p "$APP_DIR"
rm -rf "$APP_DIR/src"
cp -a "$SRC_DIR/src" "$SRC_DIR/package.json" "$SRC_DIR/package-lock.json" "$APP_DIR"/
mkdir -p "$APP_DIR/data"
(cd "$APP_DIR" && npm ci --omit=dev --omit=optional --no-audit --no-fund)
chown -R jitu-gw:jitu-gw "$APP_DIR"

if [ ! -f "$ENV_FILE" ]; then
  umask 077
  cat > "$ENV_FILE" <<ENV
GATEWAY_API_KEY=$(openssl rand -hex 24)
HOST=127.0.0.1
PORT=3100
DATA_DIR=$APP_DIR/data
MAX_SESSIONS=50
ENV
fi
chmod 600 "$ENV_FILE"

cat > /etc/systemd/system/jitu-gateway.service <<UNIT
[Unit]
Description=JITU LEAD WhatsApp gateway
After=network-online.target
Wants=network-online.target

[Service]
User=jitu-gw
WorkingDirectory=$APP_DIR
EnvironmentFile=$ENV_FILE
ExecStart=$(command -v node) src/index.js
Restart=always
RestartSec=5
NoNewPrivileges=true
ProtectSystem=strict
ReadWritePaths=$APP_DIR/data
PrivateTmp=true

[Install]
WantedBy=multi-user.target
UNIT

systemctl daemon-reload
systemctl enable jitu-gateway >/dev/null
systemctl restart jitu-gateway

KEY=$(grep '^GATEWAY_API_KEY=' "$ENV_FILE" | cut -d= -f2-)
PORT=$(grep '^PORT=' "$ENV_FILE" | cut -d= -f2-)
SECRET=""

if [ -f "$CRM_DIR/.env" ]; then
  set_env() { grep -q "^$1=" "$CRM_DIR/.env" || echo "$1=$2" >> "$CRM_DIR/.env"; }
  SECRET=$(grep '^WHATSAPP_GATEWAY_SIGNING_SECRET=' "$CRM_DIR/.env" | cut -d= -f2- || true)
  [ -n "$SECRET" ] || SECRET=$(openssl rand -hex 24)
  set_env WHATSAPP_GATEWAY_URL "http://127.0.0.1:$PORT"
  set_env WHATSAPP_GATEWAY_API_KEY "$KEY"
  set_env WHATSAPP_GATEWAY_SIGNING_SECRET "$SECRET"
  (cd "$CRM_DIR" && sudo -u www-data php artisan config:cache >/dev/null)
  echo "Isian WHATSAPP_GATEWAY_* sudah ditambahkan ke $CRM_DIR/.env dan konfigurasi CRM diperbarui."
else
  echo "File .env CRM tidak ditemukan di $CRM_DIR. Tambahkan sendiri ke .env CRM:"
  echo "  WHATSAPP_GATEWAY_URL=http://127.0.0.1:$PORT"
  echo "  WHATSAPP_GATEWAY_API_KEY=$KEY"
  echo "  WHATSAPP_GATEWAY_SIGNING_SECRET=<teks acak, bebas>"
  echo "lalu jalankan: php artisan config:cache"
fi

sleep 2
if curl -fsS "http://127.0.0.1:$PORT/health" >/dev/null; then
  echo "Gateway berjalan di 127.0.0.1:$PORT. Sekarang tambahkan nomor di CRM (Koneksi WhatsApp) lalu klik Scan QR."
else
  echo "Gateway belum menjawab. Lihat: journalctl -u jitu-gateway -n 50"
  exit 1
fi
