#!/usr/bin/env bash
# Publishes a green CI run of the developer branch to https://buildpusher.com: scripts/deploy-production.sh <run-id>.
# Each deploy is a release in /var/www/buildpusher/releases/<commit> (the API from git, its PHP dependencies, and the
# web server and admin theme GitHub built), sharing the settings, storage and database on the volume. Once it's
# migrated and cached, /var/www/buildpusher/current switches to it and the services pick it up; the previous releases
# stay for a quick roll back (point `current` at one and run this script's last step by hand). Nothing is built here
# beyond `composer install`, because the server is small.
set -euo pipefail

repo=lessbuild/app
branch=developer
base=/var/www/buildpusher
shared=/mnt/volume_nyc1_1789401255960/buildpusher-v3/shared
root="$(cd "$(dirname "$0")/.." && pwd)"
php=/root/.local/share/buildpusher/php-8.5.10/bin/php

run=${1:?Give the CI run to deploy: scripts/deploy-production.sh <run-id>}
read -r status sha < <(gh run view "$run" -R "$repo" --json conclusion,headSha -q '"\(.conclusion) \(.headSha)"')
if [ "$status" != "success" ]; then
    echo "CI run $run didn't pass ($status); not deploying." >&2
    exit 1
fi
release="$base/releases/$sha"
echo "Deploying $sha (CI run $run) to $release"

rm -rf "$release"
mkdir -p "$release/web"
git -C "$root" fetch -q origin "$branch"
git -C "$root" archive "$sha" api | tar -x -C "$release"

tmp=$(mktemp -d)
trap 'rm -rf "$tmp"' EXIT
gh run download "$run" -R "$repo" -n web-server -D "$tmp/web"
rsync -a --chmod=D755,F644 "$tmp/web"/ "$release/web"/
if gh run download "$run" -R "$repo" -n admin-theme -D "$tmp/theme"; then
    mkdir -p "$release/api/public/build"
    rsync -a --chmod=D755,F644 "$tmp/theme"/ "$release/api/public/build"/
fi

cd "$release/api"
ln -sfn "$shared/.env" .env
rm -rf storage
ln -sfn "$shared/storage" storage
COMPOSER_ALLOW_SUPERUSER=1 "$php" /usr/bin/composer install --no-dev --optimize-autoloader --no-interaction --no-progress -q
"$php" artisan storage:link -q --force
chown -R www-data:www-data "$release/api/bootstrap/cache"
runuser -u www-data -- "$php" artisan migrate --force
runuser -u www-data -- "$php" artisan optimize

ln -sfn "$release" "$base/current.next"
mv -Tf "$base/current.next" "$base/current"

kill -USR2 "$(cat /run/buildpusher/php-fpm.pid)"
systemctl restart buildpusher-web buildpusher-queue-main buildpusher-queue-telemetry

# Keep the three newest releases.
ls -1dt "$base"/releases/*/ | tail -n +4 | xargs -r rm -rf
echo "Live at https://buildpusher.com ($sha)"
