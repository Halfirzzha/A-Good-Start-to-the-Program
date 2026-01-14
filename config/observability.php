<?php

return [
    'slow_request_ms' => (int) env('OBSERVABILITY_SLOW_REQUEST_MS', 800),
    'slow_query_ms' => (int) env('OBSERVABILITY_SLOW_QUERY_MS', 500),
    'log_query_bindings' => env('OBSERVABILITY_LOG_QUERY_BINDINGS', false),
];
