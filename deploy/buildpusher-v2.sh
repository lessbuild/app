#!/usr/bin/env bash
# Deploy platform-v2 to buildpusher.com next to the old unified app, then switch Caddy and the workers over.
# The old app in /var/www/buildpusher-unified is left untouched; deploy/rollback-to-unified.sh switches back.
#
# Run as root on the server: bash deploy/buildpusher-v2.sh
# Re-running it deploys the current platform-v2 commit as a new release (the database and .env are kept).
set -Eeuo pipefail

REPO=/root/Documents/Codex/2026-09-23/plan-i-currently-have-3-applications/platform-v2
VOLUME=/mnt/volume_nyc1_1789401255960/buildpusher-v2
LINK=/var/www/buildpusher-v2
OLD_ENV=/var/www/buildpusher-unified/shared/.env
PHP=/usr/bin/php8.5
# queue:connection:worker timeout (seconds). Each timeout stays below its connection's retry_after, so a slow job is
# never picked up twice; scheduled tasks (up to an hour) run on the default queue.
QUEUES=(default:database:3700 terminals:database:3700 checks:checks:100 alerts:alerts:100 telemetry:telemetry:150)

step() { printf '\n==> %s\n' "$*"; }
fail() { printf 'ERROR: %s\n' "$*" >&2; exit 1; }

step "Checking prerequisites"
[ "$(id -u)" = 0 ] || fail "run as root"
[ -x "$PHP" ] || fail "$PHP not found"
# Read the module list once: piping php -m into grep -q under pipefail fails whenever grep exits before PHP finishes writing.
MODULES=$("$PHP" -m)
for extension in pdo_sqlite mbstring intl bcmath; do
    grep -qix "$extension" <<< "$MODULES" || fail "PHP extension $extension is missing"
done
command -v composer >/dev/null || fail "composer not found"
command -v npm >/dev/null || fail "npm not found"
[ -S /run/buildpusher/php8.5-fpm.sock ] || fail "the PHP-FPM socket /run/buildpusher/php8.5-fpm.sock isn't there"
git -C "$REPO" diff --quiet || fail "platform-v2 has uncommitted changes"

COMMIT=$(git -C "$REPO" rev-parse HEAD)
RELEASE="$VOLUME/releases/$COMMIT"
SHARED="$VOLUME/shared"

step "Preparing $RELEASE"
mkdir -p "$VOLUME/releases" "$SHARED/storage"/{app/public,framework/{cache/data,sessions,views},logs} "$SHARED/database"
ln -sfn "$VOLUME" "$LINK.tmp" && mv -Tf "$LINK.tmp" "$LINK"
rm -rf "$RELEASE"
mkdir -p "$RELEASE"
git -C "$REPO" archive "$COMMIT" | tar -x -C "$RELEASE"

step "Writing the shared .env (first deploy only)"
if [ ! -f "$SHARED/.env" ]; then
    old() { grep -E "^$1=" "$OLD_ENV" 2>/dev/null | head -1 | cut -d= -f2- || true; }
    cp "$RELEASE/.env.example" "$SHARED/.env"
    set_env() {
        local key=$1 value=$2
        if grep -qE "^$key=" "$SHARED/.env"; then
            sed -i "s|^$key=.*|$key=${value//|/\\|}|" "$SHARED/.env"
        else
            printf '%s=%s\n' "$key" "$value" >> "$SHARED/.env"
        fi
    }
    set_env APP_NAME BuildPusher
    set_env APP_ENV production
    set_env APP_DEBUG false
    set_env APP_URL https://buildpusher.com
    set_env DB_CONNECTION sqlite
    set_env DB_DATABASE "$SHARED/database/database.sqlite"
    set_env QUEUE_CONNECTION database
    set_env DB_QUEUE_RETRY_AFTER 3900
    set_env SESSION_DRIVER database
    set_env CACHE_STORE database
    set_env LOG_CHANNEL daily
    set_env MAIL_MAILER log
    set_env REGISTRATION_OPEN true
    # The same GitHub App as the old app: its webhook address is unchanged in v2.
    for key in GITHUB_APP_ID GITHUB_APP_SLUG GITHUB_APP_WEBHOOK_SECRET GITHUB_APP_PRIVATE_KEY GITHUB_APP_PRIVATE_KEY_PATH; do
        value=$(old "$key")
        [ -n "$value" ] && set_env "$key" "$value"
    done
    chown root:www-data "$SHARED/.env"
    chmod 640 "$SHARED/.env"
    NEW_KEY=1
fi
touch "$SHARED/database/database.sqlite"
chown -R www-data:www-data "$SHARED/storage" "$SHARED/database"
chmod -R ug+rwX "$SHARED/storage" "$SHARED/database"

step "Linking shared files into the release"
rm -rf "$RELEASE/storage"
ln -s "$SHARED/storage" "$RELEASE/storage"
ln -s "$SHARED/.env" "$RELEASE/.env"

step "Installing dependencies and building the front end"
cd "$RELEASE"
COMPOSER_ALLOW_SUPERUSER=1 composer install --no-dev --prefer-dist --no-interaction --no-progress --optimize-autoloader
npm ci --no-audit --no-fund
npm run build
rm -rf node_modules

if [ "${NEW_KEY:-0}" = 1 ]; then
    step "Generating the application key"
    "$PHP" artisan key:generate --force
fi

step "Migrating the database and caching configuration"
"$PHP" artisan migrate --force
"$PHP" artisan storage:link --force >/dev/null 2>&1 || true
"$PHP" artisan config:cache
"$PHP" artisan route:cache
"$PHP" artisan view:cache
"$PHP" artisan event:cache
# Analytics' IP-to-country database lives in shared storage; fetch it once, then the scheduler refreshes it monthly.
if [ ! -f "$SHARED/storage/app/geoip/dbip-country-lite.mmdb" ]; then
    sudo -u www-data "$PHP" artisan analytics:update-geoip || printf 'Could not download the country database yet; the monthly schedule will retry.\n'
fi
chown -R root:www-data "$RELEASE"
chmod -R g+rX "$RELEASE"
chown -R www-data:www-data "$RELEASE/bootstrap/cache"

step "Checking the release boots"
sudo -u www-data "$PHP" artisan about --only=environment >/dev/null
sudo -u www-data "$PHP" artisan route:list --path=up >/dev/null

step "Activating the release"
ln -sfn "$RELEASE" "$VOLUME/current.tmp" && mv -Tf "$VOLUME/current.tmp" "$VOLUME/current"

step "Installing the v2 workers and scheduler"
for entry in "${QUEUES[@]}"; do
    IFS=: read -r queue connection timeout <<< "$entry"
    cat > "/etc/systemd/system/buildpusher-v2-worker-$queue.service" <<UNIT
[Unit]
Description=BuildPusher v2 queue worker ($queue)
After=network.target

[Service]
User=www-data
Group=www-data
WorkingDirectory=$LINK/current
ExecStart=$PHP $LINK/current/artisan queue:work $connection --queue=$queue --sleep=3 --tries=3 --timeout=$timeout --max-time=3600
Restart=always
RestartSec=5

[Install]
WantedBy=multi-user.target
UNIT
done
cat > /etc/systemd/system/buildpusher-v2-schedule.service <<UNIT
[Unit]
Description=Run the BuildPusher v2 scheduler

[Service]
Type=oneshot
User=www-data
Group=www-data
WorkingDirectory=$LINK/current
ExecStart=$PHP $LINK/current/artisan schedule:run
UNIT
cat > /etc/systemd/system/buildpusher-v2-schedule.timer <<UNIT
[Unit]
Description=Run the BuildPusher v2 scheduler every minute

[Timer]
OnCalendar=*-*-* *:*:00
AccuracySec=1s
Persistent=true

[Install]
WantedBy=timers.target
UNIT
systemctl daemon-reload

step "Switching Caddy to v2 (the old Caddyfile is kept as a backup)"
BACKUP="/etc/caddy/Caddyfile.unified-$(date -u +%Y%m%d%H%M%S).bak"
cp /etc/caddy/Caddyfile "$BACKUP"
sed -i "s|root \* /var/www/buildpusher-unified/current/public|root * $LINK/current/public|" /etc/caddy/Caddyfile
# Status pages on customers' own domains: Caddy gets a certificate on the first visit, but only for hostnames the app
# confirms are a published page's verified domain. Added once; later deploys find the marker and leave it alone.
if ! grep -q "# buildpusher-status-domains" /etc/caddy/Caddyfile; then
    {
        printf '# buildpusher-status-domains\n{\n\ton_demand_tls {\n\t\task https://buildpusher.com/internal/tls/status-domain\n\t\tinterval 1m\n\t\tburst 10\n\t}\n}\n\n'
        cat /etc/caddy/Caddyfile
        cat <<CADDY

# buildpusher-status-domains: any other hostname, once the app has verified it for a status page
https:// {
	tls {
		on_demand
	}
	root * $LINK/current/public
	encode zstd gzip
	php_fastcgi unix//run/buildpusher/php8.5-fpm.sock
	file_server
	header {
		-Server
		X-Content-Type-Options "nosniff"
		Referrer-Policy "strict-origin-when-cross-origin"
	}
}
CADDY
    } > /etc/caddy/Caddyfile.next
    mv /etc/caddy/Caddyfile.next /etc/caddy/Caddyfile
fi
caddy validate --config /etc/caddy/Caddyfile --adapter caddyfile >/dev/null || { cp "$BACKUP" /etc/caddy/Caddyfile; fail "the new Caddyfile didn't validate; restored $BACKUP"; }
systemctl reload caddy

step "Stopping the old workers and scheduler, starting v2's"
systemctl disable --now buildpusher-schedule.timer 'buildpusher-worker@*.service' 2>/dev/null || true
for entry in "${QUEUES[@]}"; do
    queue=${entry%%:*}
    systemctl enable --now "buildpusher-v2-worker-$queue.service"
    systemctl restart "buildpusher-v2-worker-$queue.service"
done
systemctl enable --now buildpusher-v2-schedule.timer
# PHP-FPM caches opcode for the old paths; restart it so the new release is served fresh.
systemctl restart buildpusher-php-fpm.service

step "Checking buildpusher.com"
for path in /up / /pricing /status/report.json /api/openapi.json /login /register; do
    code=$(curl -s -o /dev/null -w '%{http_code}' --resolve buildpusher.com:443:127.0.0.1 "https://buildpusher.com$path")
    printf '  %-22s %s\n' "$path" "$code"
    [ "$code" = 200 ] || fail "$path answered $code; run deploy/rollback-to-unified.sh to switch back"
done

printf '\nDeployed %s. Rollback: bash %s/deploy/rollback-to-unified.sh\n' "$COMMIT" "$REPO"
printf 'Next: set PLATFORM_ADMIN_EMAILS (then php artisan platform:admin --import-allowlist), Stripe keys and price IDs, and a real MAIL_MAILER in %s/.env, then php artisan config:cache.\n' "$SHARED"
