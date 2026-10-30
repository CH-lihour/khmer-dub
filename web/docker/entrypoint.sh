#!/bin/sh
# Prepares a bind-mounted checkout (fresh clone included) before running the command.
set -e

if [ ! -f vendor/autoload.php ]; then
    composer install --no-interaction --prefer-dist --no-progress
fi

if [ ! -f .env ]; then
    echo "web/.env is missing. Run: cp web/.env.example web/.env" >&2
    exit 1
fi

# env_file passes an empty APP_KEY on the first start; generate it once into .env.
if [ -z "${APP_KEY:-}" ]; then
    if ! grep -qE '^APP_KEY=.+' .env; then
        php artisan key:generate --force --no-interaction
    fi
    APP_KEY="$(grep -E '^APP_KEY=' .env | cut -d= -f2-)"
    export APP_KEY
fi

exec "$@"
