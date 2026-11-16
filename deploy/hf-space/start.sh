#!/usr/bin/env bash
# Starts the whole site in one container (Hugging Face Space): worker, queue, scheduler and web on port 7860.
# Everything under /data and the site's storage is lost when the Space restarts.
set -euo pipefail

random_hex() { python -c 'import secrets; print(secrets.token_hex(32))'; }

# The worker and the site run side by side here, so one random token per start is enough.
export WORKER_TOKEN="${WORKER_TOKEN:-$(random_hex)}"
export DUBBER_TOKEN="$WORKER_TOKEN"
# Logins end at every restart anyway (the database is wiped), so a new key per start is fine.
export APP_KEY="${APP_KEY:-base64:$(python -c 'import base64, os; print(base64.b64encode(os.urandom(32)).decode())')}"
# Hugging Face sets SPACE_HOST to the Space's own address, e.g. name-khmer-dub.hf.space.
if [ -n "${SPACE_HOST:-}" ]; then
    export APP_URL="${APP_URL:-https://$SPACE_HOST}"
else
    export APP_URL="${APP_URL:-http://localhost:7860}"
fi

mkdir -p "$WORK_DIR" "$(dirname "$DB_DATABASE")"
touch "$DB_DATABASE"

cd /app/web
mkdir -p storage/app/private storage/app/public storage/logs bootstrap/cache \
    storage/framework/cache/data storage/framework/sessions storage/framework/views
php artisan config:cache --no-interaction
php artisan view:cache --no-interaction
php artisan migrate --force --no-interaction

# Runs a command again 5 s after it stops, so one crash does not take the Space down.
keep_running() {
    local name="$1"
    shift
    while true; do
        "$@" || true
        echo "[$name] stopped; starting it again in 5 s" >&2
        sleep 5
    done
}

trap 'kill 0' TERM INT

(cd /app/worker && keep_running worker uvicorn app:app --host 127.0.0.1 --port 8001) &

echo "Waiting for the worker..."
for _ in $(seq 1 120); do
    curl -fsS http://127.0.0.1:8001/health > /dev/null 2>&1 && break
    sleep 1
done

keep_running queue php artisan queue:work --timeout=3700 --tries=1 &
keep_running scheduler php artisan schedule:work &
# Four PHP processes, so the video player does not hold up the site.
keep_running web env PHP_CLI_SERVER_WORKERS=4 php artisan serve --host=0.0.0.0 --port=7860 --no-reload &

wait
