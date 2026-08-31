#!/usr/bin/env bash

set -Eeuo pipefail

APP_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
PHP_BIN="$(command -v php)"
SERVICE_NAME="lessbuild-app"
SERVICE_FILE="/etc/systemd/system/${SERVICE_NAME}.service"
WORKER_SERVICE_NAME="lessbuild-worker"
WORKER_SERVICE_FILE="/etc/systemd/system/${WORKER_SERVICE_NAME}.service"
BACKUP_SERVICE_NAME="lessbuild-backup"
BACKUP_SERVICE_FILE="/etc/systemd/system/${BACKUP_SERVICE_NAME}.service"
BACKUP_TIMER_NAME="lessbuild-backup"
BACKUP_TIMER_FILE="/etc/systemd/system/${BACKUP_TIMER_NAME}.timer"
WATCHDOG_SERVICE_NAME="lessbuild-watchdog"
WATCHDOG_SERVICE_FILE="/etc/systemd/system/${WATCHDOG_SERVICE_NAME}.service"
WATCHDOG_TIMER_NAME="lessbuild-watchdog"
WATCHDOG_TIMER_FILE="/etc/systemd/system/${WATCHDOG_TIMER_NAME}.timer"
PUBLIC_IP="${1:-$(hostname -I | awk '{print $1}')}"

if [[ "${EUID}" -ne 0 ]]; then
    echo "Run this installer as root so it can configure systemd." >&2
    exit 1
fi

if [[ ! -f "${APP_DIR}/artisan" ]]; then
    echo "Laravel artisan executable not found in ${APP_DIR}." >&2
    exit 1
fi

set_env_value() {
    local key="$1"
    local value="$2"

    if grep -q "^${key}=" "${APP_DIR}/.env"; then
        sed -i "s|^${key}=.*|${key}=${value}|" "${APP_DIR}/.env"
    else
        printf '%s=%s\n' "${key}" "${value}" >> "${APP_DIR}/.env"
    fi
}

if [[ ! -f "${APP_DIR}/.env" ]]; then
    install -m 600 "${APP_DIR}/.env.example" "${APP_DIR}/.env"
    sed -i "s|^APP_URL=.*|APP_URL=http://${PUBLIC_IP}:8003|" "${APP_DIR}/.env"
    sed -i 's|^DB_CONNECTION=.*|DB_CONNECTION=sqlite|' "${APP_DIR}/.env"
    sed -i "s|^DB_DATABASE=.*|DB_DATABASE=${APP_DIR}/database/database.sqlite|" "${APP_DIR}/.env"
    install -m 660 /dev/null "${APP_DIR}/database/database.sqlite"
    "${PHP_BIN}" "${APP_DIR}/artisan" key:generate --force
fi

set_env_value APP_ENV production
set_env_value APP_DEBUG false
set_env_value APP_URL "http://${PUBLIC_IP}:8003"
set_env_value LOG_LEVEL info

if grep -q '^QUEUE_CONNECTION=sync$' "${APP_DIR}/.env"; then
    set_env_value QUEUE_CONNECTION database
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
Environment=APP_DEBUG=false

[Install]
WantedBy=multi-user.target
SERVICE

cat > "${WORKER_SERVICE_FILE}" <<SERVICE
[Unit]
Description=Lessbuild background queue worker
After=network-online.target
Wants=network-online.target

[Service]
Type=simple
User=root
Group=root
WorkingDirectory=${APP_DIR}
ExecStart=${PHP_BIN} artisan queue:work --queue=default --sleep=2 --tries=3 --timeout=80 --max-time=3600
Restart=always
RestartSec=3
TimeoutStopSec=90
KillSignal=SIGTERM
Environment=APP_ENV=production
Environment=APP_DEBUG=false

[Install]
WantedBy=multi-user.target
SERVICE

cat > "${BACKUP_SERVICE_FILE}" <<SERVICE
[Unit]
Description=Lessbuild consistent SQLite database backup
After=network-online.target

[Service]
Type=oneshot
User=root
Group=root
WorkingDirectory=${APP_DIR}
ExecStart=${PHP_BIN} artisan lessbuild:backup
Nice=10
UMask=0027
Environment=APP_ENV=production
Environment=APP_DEBUG=false
SERVICE

cat > "${BACKUP_TIMER_FILE}" <<TIMER
[Unit]
Description=Run the Lessbuild database backup daily

[Timer]
OnCalendar=daily
RandomizedDelaySec=30m
Persistent=true
Unit=${BACKUP_SERVICE_NAME}.service

[Install]
WantedBy=timers.target
TIMER

cat > "${WATCHDOG_SERVICE_FILE}" <<SERVICE
[Unit]
Description=Recover stale Lessbuild deployments
After=network-online.target
Wants=network-online.target

[Service]
Type=oneshot
User=root
Group=root
WorkingDirectory=${APP_DIR}
ExecStart=${PHP_BIN} artisan lessbuild:deployments:watchdog
TimeoutStartSec=120
Nice=5
Environment=APP_ENV=production
Environment=APP_DEBUG=false
SERVICE

cat > "${WATCHDOG_TIMER_FILE}" <<TIMER
[Unit]
Description=Check for stale Lessbuild deployments every minute

[Timer]
OnCalendar=*-*-* *:*:00
Persistent=true
Unit=${WATCHDOG_SERVICE_NAME}.service

[Install]
WantedBy=timers.target
TIMER

systemctl daemon-reload
systemctl enable "${SERVICE_NAME}.service" "${WORKER_SERVICE_NAME}.service"
systemctl enable --now "${WATCHDOG_TIMER_NAME}.timer"
DATABASE_CONNECTION="$(sed -n 's/^DB_CONNECTION=//p' "${APP_DIR}/.env" | tail -n 1 | tr -d "\"'")"
if [[ "${DATABASE_CONNECTION}" == "sqlite" ]]; then
    systemctl enable --now "${BACKUP_TIMER_NAME}.timer"
else
    systemctl disable --now "${BACKUP_TIMER_NAME}.timer" 2>/dev/null || true
fi
systemctl restart "${SERVICE_NAME}.service" "${WORKER_SERVICE_NAME}.service"

echo "Lessbuild is running at http://${PUBLIC_IP}:8003"
systemctl --no-pager --full status "${SERVICE_NAME}.service"
systemctl --no-pager --full status "${WORKER_SERVICE_NAME}.service"
systemctl --no-pager --full status "${WATCHDOG_TIMER_NAME}.timer"
if [[ "${DATABASE_CONNECTION}" == "sqlite" ]]; then
    systemctl --no-pager --full status "${BACKUP_TIMER_NAME}.timer"
else
    echo "Automatic database backups are disabled because DB_CONNECTION is not sqlite."
fi
