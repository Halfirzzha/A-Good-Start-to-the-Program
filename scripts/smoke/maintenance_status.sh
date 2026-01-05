#!/usr/bin/env bash
set -euo pipefail

base_url="${1:-${APP_URL:-http://127.0.0.1:8000}}"

response="$(curl -fsS "${base_url}/maintenance/status")"

php -r '
$payload = json_decode(stream_get_contents(STDIN), true);
if (!is_array($payload)) {
    fwrite(STDERR, "Invalid JSON response\n");
    exit(1);
}
$required = ["status_label", "is_active", "server_now", "timezone"];
foreach ($required as $key) {
    if (!array_key_exists($key, $payload)) {
        fwrite(STDERR, "Missing key: {$key}\n");
        exit(1);
    }
}
if (!is_bool($payload["is_active"])) {
    fwrite(STDERR, "is_active must be boolean\n");
    exit(1);
}
echo "maintenance/status OK\n";
' <<<"$response"
