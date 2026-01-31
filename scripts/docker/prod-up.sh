#!/usr/bin/env sh
set -eu

ROOT_DIR=$(CDPATH= cd -- "$(dirname -- "$0")/../.." && pwd)
cd "$ROOT_DIR"

if [ ! -f .env ]; then
  cp .env.prod.example .env
fi

docker compose -f docker-compose.prod.yml build

docker compose -f docker-compose.prod.yml up -d

if ! grep -q '^APP_KEY=base64:' .env; then
  docker compose -f docker-compose.prod.yml run --rm app php artisan key:generate --force
fi

docker compose -f docker-compose.prod.yml run --rm app php artisan migrate --force

docker compose -f docker-compose.prod.yml run --rm app php artisan storage:link || true

docker compose -f docker-compose.prod.yml run --rm app php artisan config:cache

docker compose -f docker-compose.prod.yml run --rm app php artisan route:cache

docker compose -f docker-compose.prod.yml run --rm app php artisan view:cache
