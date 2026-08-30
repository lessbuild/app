#!/usr/bin/env bash

set -Eeuo pipefail

APP_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
PHP_BIN="$(command -v php)"
SERVICE_NAME="lessbuild-app"
SERVICE_FILE="/etc/systemd/system/${SERVICE_NAME}.service"
PUBLIC_IP="${1:-$(hostname -I | awk '{print $1}')}"

if [[ "${EUID}" -ne 0 ]]; then
    echo "Run this installer as root so it can configure systemd." >&2
    exit 1
fi

if [[ ! -f "${APP_DIR}/artisan" ]]; then
    echo "Laravel artisan executable not found in ${APP_DIR}." >&2
    exit 1
fi

if [[ ! -f "${APP_DIR}/.env" ]]; then
    install -m 600 "${APP_DIR}/.env.example" "${APP_DIR}/.env"
    sed -i "s|^APP_URL=.*|APP_URL=http://${PUBLIC_IP}:8003|" "${APP_DIR}/.env"
    sed -i 's|^DB_CONNECTION=.*|DB_CONNECTION=sqlite|' "${APP_DIR}/.env"
    sed -i "s|^DB_DATABASE=.*|DB_DATABASE=${APP_DIR}/database/database.sqlite|" "${APP_DIR}/.env"
    install -m 660 /dev/null "${APP_DIR}/database/database.sqlite"
    "${PHP_BIN}" "${APP_DIR}/artisan" key:generate --force
fi

"${PHP_BIN}" "${APP_DIR}/artisan" migrate --force
"${PHP_BIN}" "${APP_DIR}/artisan" optimize:clear

cat > "${SERVICE_FILE}" <<SERVICE
[Unit]
Description=Lessbuild Laravel application on port 8003
After=network-online.target
Wants=network-online.target

[Service]
Type=simple
User=root
Group=root
WorkingDirectory=${APP_DIR}
ExecStart=${PHP_BIN} artisan serve --host=0.0.0.0 --port=8003 --no-reload
Restart=always
RestartSec=3
TimeoutStopSec=15
KillSignal=SIGTERM
Environment=APP_ENV=production

[Install]
WantedBy=multi-user.target
SERVICE

systemctl daemon-reload
systemctl enable --now "${SERVICE_NAME}.service"

echo "Lessbuild is running at http://${PUBLIC_IP}:8003"
systemctl --no-pager --full status "${SERVICE_NAME}.service"
