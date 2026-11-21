#!/usr/bin/env bash
set -euo pipefail
umask 0027
base=/srv/kioosk
exec 9>"$base/shared/deploy.lock"
flock 9

publish() {
    local release=$1
    cd "$release"
    ln -s "$base/shared/.env" .env
    mv storage "$release/storage-template"
    mkdir -p "$base/shared/storage/"{app/private,app/public,framework/cache/data,framework/sessions,framework/views,logs}
    ln -s "$base/shared/storage" storage
    mkdir -p bootstrap/cache
    chmod g+w bootstrap/cache
    if [[ -d "$base/current/public/build/assets" ]]; then
        mkdir -p public/build/assets
        cp -an "$base/current/public/build/assets/." public/build/assets/
    fi
    php artisan migrate --force
    php artisan view:cache
    php artisan route:cache
    chmod -R g+rX bootstrap/cache
    ln -sfn "$release" "$base/next"
    mv -Tf "$base/next" "$base/current"
}

if [[ ${1:-} == --publish-local ]]; then
    publish "$2"
    exit
fi

repo=https://github.com/AmirMehrabi/Kioosk-space.git
if [[ ! -d "$base/source/.git" ]]; then
    git init -q "$base/source"
fi
git -C "$base/source" fetch --depth=1 "$repo" refs/heads/main
commit=$(git -C "$base/source" rev-parse FETCH_HEAD)
release="$base/releases/$commit-$(date +%s)"
mkdir "$release"
git -C "$base/source" archive "$commit" | tar -xf - -C "$release"
cd "$release"
composer install --no-interaction --prefer-dist
npm ci --no-audit --no-fund
npm run build
APP_KEY="base64:$(openssl rand -base64 32)" php artisan test --compact
composer install --no-dev --no-interaction --prefer-dist --optimize-autoloader
publish "$release"
