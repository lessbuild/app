#!/usr/bin/env bash
# Puts BuildPusher's own details in production's settings and fixes how the Nuxt server reaches Laravel:
# scripts/configure-production.sh [--mail-from address]
#
# 1. The shared .env gets APP_NAME=BuildPusher and a real sender address (the defaults said "Laravel" and
#    hello@example.com), then Laravel's caches are rebuilt and the queue workers restarted to read them.
# 2. Caddy's internal listener (127.0.0.1:8100) keeps the visitor's X-Forwarded-For, -Host and -Proto that the Nuxt
#    server forwards. Without it Laravel builds http://127.0.0.1:443 links (canonical, link previews) and rate limits
#    every visitor as one. The new config is validated before Caddy reloads; a failure puts the old one back.
#
# Both files are backed up next to themselves first. Running it again changes nothing that's already done.
set -euo pipefail

env_file=/mnt/volume_nyc1_1789401255960/buildpusher-v3/shared/.env
caddy_file=/etc/caddy/buildpusher-production.caddy
api=/var/www/buildpusher/current/api
php=/root/.local/share/buildpusher/php-8.5.10/bin/php
mail_from=support@buildpusher.com
stamp=$(date +%Y%m%d%H%M%S)

while [ $# -gt 0 ]; do
    case "$1" in
        --mail-from) mail_from=${2:?--mail-from needs an address}; shift 2 ;;
        *) echo "Unknown option: $1" >&2; exit 1 ;;
    esac
done

if [ "$(id -u)" -ne 0 ]; then
    echo "Run this as root." >&2
    exit 1
fi

# Set KEY=value in the .env, replacing the line if it's there or adding it if not.
set_env() {
    local key=$1 value=$2
    if grep -q "^${key}=" "$env_file"; then
        sed -i "s|^${key}=.*|${key}=${value}|" "$env_file"
    else
        printf '%s=%s\n' "$key" "$value" >> "$env_file"
    fi
}

echo "== Settings ($env_file)"
cp -a "$env_file" "$env_file.bak-$stamp"
set_env APP_NAME BuildPusher
set_env MAIL_FROM_ADDRESS "\"$mail_from\""
set_env MAIL_FROM_NAME '"${APP_NAME}"'
grep -E '^(APP_NAME|MAIL_FROM_ADDRESS|MAIL_FROM_NAME|MAIL_MAILER)=' "$env_file"

echo "== Caddy ($caddy_file)"
if grep -q 'header_up X-Forwarded-Host' "$caddy_file"; then
    echo "Already keeps the forwarded headers."
else
    cp -a "$caddy_file" "$caddy_file.before-forwarded-$stamp.bak"
    python3 - "$caddy_file" <<'EOF'
import sys
path = sys.argv[1]
text = open(path).read()
old = """# Where the Nuxt server reaches Laravel.
http://127.0.0.1:8100 {
	bind 127.0.0.1
	root * /var/www/buildpusher/current/api/public
	php_fastcgi unix//run/buildpusher/php8.5-fpm.sock
"""
new = """# Where the Nuxt server reaches Laravel. Only it calls here, so keep the visitor's address, host and scheme it
# forwards (Caddy would otherwise replace them with its own, and Laravel would build 127.0.0.1 links and rate limit
# every visitor as one).
http://127.0.0.1:8100 {
	bind 127.0.0.1
	root * /var/www/buildpusher/current/api/public
	php_fastcgi unix//run/buildpusher/php8.5-fpm.sock {
		header_up X-Forwarded-For {http.request.header.X-Forwarded-For}
		header_up X-Forwarded-Host {http.request.header.X-Forwarded-Host}
		header_up X-Forwarded-Proto {http.request.header.X-Forwarded-Proto}
	}
"""
if old not in text:
    sys.exit("The 127.0.0.1:8100 block isn't as expected; edit it by hand.")
open(path, "w").write(text.replace(old, new))
EOF
    if ! caddy validate --config /etc/caddy/Caddyfile --adapter caddyfile >/dev/null 2>&1; then
        cp -a "$caddy_file.before-forwarded-$stamp.bak" "$caddy_file"
        echo "Caddy rejected the new config; the old one is back in place." >&2
        exit 1
    fi
    systemctl reload caddy
    echo "Reloaded Caddy."
fi

echo "== Laravel"
cd "$api"
runuser -u www-data -- "$php" artisan optimize >/dev/null
systemctl restart buildpusher-web buildpusher-queue-main buildpusher-queue-telemetry
echo "Caches rebuilt; web and queue workers restarted."

echo "== Check"
sleep 3
page=$(curl -s https://buildpusher.com/compare/ploi)
title=$(grep -o '<title>[^<]*' <<<"$page" | sed 's/<title>//')
canonical=$(grep -o '<link rel="canonical" href="[^"]*"' <<<"$page" | sed 's/.*href="//; s/"$//')
echo "Title:     $title"
echo "Canonical: $canonical"
status=0
[[ "$title" == BuildPusher* ]] || { echo "The title still doesn't start with BuildPusher." >&2; status=1; }
[[ "$canonical" == https://buildpusher.com/* ]] || { echo "The canonical address isn't https://buildpusher.com yet." >&2; status=1; }
for path in / /login /register /pricing; do
    code=$(curl -s -o /dev/null -w '%{http_code}' "https://buildpusher.com$path")
    echo "$path $code"
    [ "$code" = 200 ] || status=1
done
if grep -q '^MAIL_MAILER=log' "$env_file"; then
    echo "Note: MAIL_MAILER=log, so email is written to the log, not sent. Add SMTP or a mail provider to send it."
fi
[ $status -eq 0 ] && echo "All good." || echo "Something above needs a look." >&2
exit $status
