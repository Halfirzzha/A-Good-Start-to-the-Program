#!/usr/bin/env bash
set -euo pipefail

root_dir="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
cd "$root_dir"

app_url="${APP_URL:-http://127.0.0.1:8000}"
skip_smoke="${SKIP_SMOKE:-0}"

printf '\n[1/4] Backend tests\n'
php artisan test

printf '\n[2/4] Frontend build\n'
if command -v npm >/dev/null 2>&1; then
    npm run build
else
    echo "npm not found" >&2
    exit 1
fi

printf '\n[3/4] Smoke: maintenance status\n'
if [ "$skip_smoke" = "1" ]; then
    echo "SKIPPED (SKIP_SMOKE=1)"
else
    if ! bash scripts/smoke/maintenance_status.sh "$app_url"; then
        echo "Smoke check failed. Is the app running at ${app_url}?" >&2
        exit 2
    fi
fi

printf '\n[4/4] Smoke: health check\n'
if [ "$skip_smoke" = "1" ]; then
    echo "SKIPPED (SKIP_SMOKE=1)"
elif command -v curl >/dev/null 2>&1; then
    if ! response="$(curl -fsS "${app_url}/health/check")"; then
        echo "Health check failed. Is the app running at ${app_url}?" >&2
        exit 2
    fi
    php -r '
$payload = json_decode(stream_get_contents(STDIN), true);
if (!is_array($payload)) {
    fwrite(STDERR, "Invalid JSON response\n");
    exit(1);
}
echo "health/check OK\n";
' <<<"$response"
else
    echo "curl not found" >&2
    exit 1
fi

printf '\nAll checks passed.\n'
