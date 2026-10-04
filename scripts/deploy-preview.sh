#!/usr/bin/env bash
# Publishes a green CI build of web/ to the preview at http://174.138.39.41:8021: the run given
# (scripts/deploy-preview.sh <run-id>), else the newest green one. Pass the run you just watched: GitHub can
# take a moment to list a finished run as successful, and the newest green one may still be the one before it.
# GitHub builds it (the server is too small); this only downloads the "web-server" artifact and restarts.
# The API runs straight from this checkout, so migrations are run here too.
set -euo pipefail

repo=lessbuild/app
branch=platform-v3
target=/var/www/buildpusher-v3/web
root="$(cd "$(dirname "$0")/.." && pwd)"

run=${1:-$(gh run list -R "$repo" -b "$branch" -w CI -s success -L 1 --json databaseId -q '.[0].databaseId')}
echo "Deploying CI run $run"

tmp=$(mktemp -d)
trap 'rm -rf "$tmp"' EXIT
gh run download "$run" -R "$repo" -n web-server -D "$tmp"

mkdir -p "$target"
rsync -a --delete --chmod=D755,F644 "$tmp"/ "$target"/

# The admin panel's theme (Filament), built by the same CI run.
theme=$(mktemp -d)
trap 'rm -rf "$tmp" "$theme"' EXIT
# Optional, so an older run without it still deploys (and the services still restart).
if gh run download "$run" -R "$repo" -n admin-theme -D "$theme"; then
    mkdir -p "$root/api/public/build"
    rsync -a --delete --chmod=D755,F644 "$theme"/ "$root/api/public/build"/
else
    echo "No admin theme in run $run; keeping the current one."
fi

(cd "$root/api" && php8.5 artisan migrate --force && php8.5 artisan optimize:clear >/dev/null)

systemctl restart buildpusher-v3-api buildpusher-v3-web buildpusher-v3-queue
echo "Live at http://174.138.39.41:8021"
