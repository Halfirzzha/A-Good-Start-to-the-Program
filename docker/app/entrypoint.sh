#!/bin/sh
set -e

mkdir -p storage/framework/cache storage/framework/sessions storage/framework/views bootstrap/cache
chmod -R ug+rw storage/framework bootstrap/cache

exec "$@"
