#!/usr/bin/env sh
set -eu

ROOT_DIR=$(CDPATH= cd -- "$(dirname -- "$0")/../.." && pwd)
cd "$ROOT_DIR"

if [ ! -f .env ]; then
  cp .env.docker.example .env
fi

if [ -z "${UID:-}" ]; then
  UID=$(id -u)
  export UID
fi

if [ -z "${GID:-}" ]; then
  GID=$(id -g)
  export GID
fi

docker compose build

docker compose up -d

docker compose run --rm app composer install --no-interaction

if ! grep -q '^APP_KEY=base64:' .env; then
  docker compose run --rm app php artisan key:generate
fi

docker compose run --rm app php artisan migrate

docker compose run --rm app php artisan storage:link || true

docker compose up -d node
