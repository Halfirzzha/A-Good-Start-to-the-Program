#!/bin/sh
set -e

if [ ! -d "storage" ]; then
  mkdir -p storage
fi

if [ ! -d "bootstrap/cache" ]; then
  mkdir -p bootstrap/cache
fi

chmod -R ug+rw storage bootstrap/cache

exec "$@"
