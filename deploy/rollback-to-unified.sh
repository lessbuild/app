#!/usr/bin/env bash
# Switch buildpusher.com back from platform-v2 to the old unified app. v2's release, database and .env stay on disk.
# Run as root on the server: bash deploy/rollback-to-unified.sh
set -Eeuo pipefail

[ "$(id -u)" = 0 ] || { echo 'run as root' >&2; exit 1; }

echo '==> Pointing Caddy back at the unified app'
cp /etc/caddy/Caddyfile "/etc/caddy/Caddyfile.v2-$(date -u +%Y%m%d%H%M%S).bak"
sed -i 's|root \* /var/www/buildpusher-v2/current/public|root * /var/www/buildpusher-unified/current/public|' /etc/caddy/Caddyfile
caddy validate --config /etc/caddy/Caddyfile --adapter caddyfile >/dev/null
systemctl reload caddy

echo '==> Stopping v2 workers and scheduler, starting the old ones'
systemctl disable --now buildpusher-v2-schedule.timer 'buildpusher-v2-worker-*.service' 2>/dev/null || true
for queue in alerts analytics checks database telemetry; do
    systemctl enable --now "buildpusher-worker@$queue.service"
done
systemctl enable --now buildpusher-schedule.timer
systemctl restart buildpusher-php-fpm.service

code=$(curl -s -o /dev/null -w '%{http_code}' --resolve buildpusher.com:443:127.0.0.1 https://buildpusher.com/)
echo "==> buildpusher.com / answered $code"
