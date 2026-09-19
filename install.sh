#!/usr/bin/env bash
# Run this ONCE, with internet access, from an EMPTY directory that
# already contains this repo's app/, database/, routes/, docs/, docker/,
# composer.json, .env.example, README.md, bootstrap/app.php, config/permission.php.
#
# It scaffolds the standard Laravel framework files (artisan, public/index.php,
# config/*.php, vendor/ etc.) via composer, WITHOUT overwriting the custom
# files already in this repo, then installs the extra packages this project
# needs (Sanctum, Spatie Permission, Swagger, Predis).
set -e

echo "==> Backing up custom project files..."
TMP=$(mktemp -d)
cp -r app database routes docs docker bootstrap config composer.json .env.example README.md "$TMP"/

echo "==> Scaffolding a fresh Laravel 11 skeleton into a temp folder..."
composer create-project laravel/laravel:^11.0 "$TMP/laravel-fresh" --no-interaction

echo "==> Merging: fresh framework files first, then restoring our custom files on top..."
rsync -a --exclude 'app' --exclude 'database/migrations' --exclude 'database/seeders' \
         --exclude 'database/factories' --exclude 'routes' --exclude 'bootstrap/app.php' \
         --exclude 'bootstrap/cache' --exclude 'vendor' \
         --exclude 'composer.json' --exclude 'composer.lock' --exclude '.env.example' --exclude 'README.md' \
         "$TMP/laravel-fresh"/ ./

# The fresh skeleton's composer.lock (if any leaked through) won't match our
# composer.json (extra packages: Sanctum, Spatie Permission, Swagger, Predis),
# so composer install would fail with a lock-mismatch error. Remove it and
# let composer resolve a fresh lock for OUR composer.json instead.
rm -f composer.lock
# Likewise, any pre-existing vendor/ or bootstrap/cache/*.php (package
# discovery cache) belongs to the fresh skeleton's OWN dependency set
# (e.g. laravel/pail) and will reference classes that don't exist once
# OUR composer.json is installed instead. Force a clean re-resolve.
rm -rf vendor
rm -f bootstrap/cache/*.php

cp -r "$TMP"/app/* app/
cp -r "$TMP"/database/* database/
cp -r "$TMP"/routes/* routes/
cp -r "$TMP"/docker .
cp -r "$TMP"/docs .
cp "$TMP"/bootstrap/app.php bootstrap/app.php
cp "$TMP"/config/permission.php config/permission.php
cp "$TMP"/composer.json composer.json
cp "$TMP"/.env.example .env.example
cp "$TMP"/README.md README.md

echo "==> Installing dependencies (composer update, since composer.lock was intentionally removed above)..."
composer update

echo "==> Re-running package discovery against the packages we actually installed..."
rm -f bootstrap/cache/*.php

cp .env.example .env
php artisan key:generate

echo "==> Done. Next: docker-compose up -d --build, then php artisan migrate --seed"
rm -rf "$TMP"
