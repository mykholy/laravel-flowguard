#!/usr/bin/env bash
set -euo pipefail

source_dir=$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)
repo_dir=$(cd "$source_dir/../.." && pwd)
target_dir=${1:-/tmp/flowguard-checkout-demo}
laravel_version=${DEMO_LARAVEL:-13}
case "$laravel_version" in 12|13) ;; *) echo 'DEMO_LARAVEL must be 12 or 13.' >&2; exit 1 ;; esac
if [ -e "$target_dir" ]; then
  echo 'Choose a new demo directory; existing projects are never overwritten.' >&2
  exit 1
fi
composer create-project "laravel/laravel:^$laravel_version" "$target_dir" --no-interaction --prefer-dist --no-progress
cp -R "$source_dir/overlay/." "$target_dir/"
cp -R "$source_dir/sandbox" "$target_dir/demo-sandbox"
php "$source_dir/configure.php" "$target_dir"
(cd "$target_dir" && composer config repositories.flowguard path "$repo_dir" && composer require 'mykholy/laravel-flowguard:@dev' 'stripe/stripe-php:^22.0' --no-interaction --with-all-dependencies --no-progress && php artisan migrate --force && php artisan flowguard:doctor)
echo "Demo created at $target_dir. Tokens are stored in its private .env file."
