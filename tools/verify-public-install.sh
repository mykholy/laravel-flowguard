#!/usr/bin/env bash
set -euo pipefail

tag=$(node -p "require('./.release/preview.json').tag")
export RELEASE_SHA
RELEASE_SHA=$(git rev-parse "$tag^{commit}")
export APP_ENV=testing
export DB_CONNECTION=sqlite
export DB_DATABASE="$RUNNER_TEMP/flowguard-release.sqlite"
touch "$DB_DATABASE"
composer create-project 'laravel/laravel:^13.0' "$RUNNER_TEMP/flowguard-release-app" --no-interaction --prefer-dist --no-progress
cd "$RUNNER_TEMP/flowguard-release-app"
constraint=${tag#v}
if [ "$(node -p "require('$GITHUB_WORKSPACE/.release/preview.json').channel || 'beta'")" = stable ]; then
  constraint="^${tag#v}"
fi
composer require "mykholy/laravel-flowguard:$constraint" --no-interaction --prefer-dist --no-progress
node -e 'const lock = JSON.parse(require("node:fs").readFileSync("composer.lock", "utf8")); const p = lock.packages.find(p => p.name === "mykholy/laravel-flowguard"); if (p?.source?.reference !== process.env.RELEASE_SHA) throw new Error("Installed source does not match the release tag");'
php artisan key:generate
php artisan migrate --force
php artisan flowguard:doctor
php "$GITHUB_WORKSPACE/tests/Installation/verify.php"
