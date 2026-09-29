#!/usr/bin/env bash
# Starts a throwaway copy of the platform for the end-to-end tests: its own SQLite database, file sessions and cache,
# mail written to a log the tests read, and no cached config, so nothing touches a real database, queue or mailbox.
set -euo pipefail
cd "$(dirname "$0")/.."
export APP_NAME=BuildPusher APP_ENV=e2e APP_DEBUG=true APP_URL=http://127.0.0.1:8123
export APP_CONFIG_CACHE="$PWD/storage/framework/e2e-config.php" APP_ROUTES_CACHE="$PWD/storage/framework/e2e-routes.php" APP_EVENTS_CACHE="$PWD/storage/framework/e2e-events.php"
export DB_CONNECTION=sqlite DB_DATABASE="$PWD/storage/e2e.sqlite" DB_URL=
export SESSION_DRIVER=file CACHE_STORE=file QUEUE_CONNECTION=database BROADCAST_CONNECTION=log
export MAIL_MAILER=log LOG_CHANNEL=single LOG_PATH="$PWD/storage/logs/e2e.log"
export TURNSTILE_SITE_KEY= TURNSTILE_SECRET_KEY= STRIPE_SECRET= STRIPE_KEY=
rm -f storage/e2e.sqlite storage/logs/e2e.log "$APP_CONFIG_CACHE" "$APP_ROUTES_CACHE" "$APP_EVENTS_CACHE"
touch storage/e2e.sqlite
php artisan migrate --force --no-interaction >/dev/null
php artisan cache:clear >/dev/null
export PHP_CLI_SERVER_WORKERS=4
exec php artisan serve --host=127.0.0.1 --port=8123 --no-reload
