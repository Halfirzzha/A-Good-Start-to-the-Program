<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Audit Logging Configuration
    |--------------------------------------------------------------------------
    |
    | Enterprise-grade audit logging with tamper-evident hash chain,
    | HMAC signatures, and comprehensive activity tracking.
    |
    */

    'enabled' => env('AUDIT_LOG_ENABLED', true),
    'admin_path' => env('AUDIT_LOG_ADMIN_PATH', 'admin'),
    'log_admin' => env('AUDIT_LOG_ADMIN_ALL', true),

    /*
    |--------------------------------------------------------------------------
    | HTTP Methods to Log
    |--------------------------------------------------------------------------
    |
    | Only log requests using these HTTP methods. GET requests are excluded
    | by default to reduce log volume while capturing all state changes.
    |
    */
    'http_methods' => array_values(array_filter(array_map('trim', explode(',', env('AUDIT_LOG_METHODS', 'POST,PUT,PATCH,DELETE'))))),

    /*
    |--------------------------------------------------------------------------
    | Paths to Ignore
    |--------------------------------------------------------------------------
    |
    | These paths are excluded from audit logging and threat detection.
    | Livewire paths are excluded to prevent false positives from AJAX calls.
    |
    */
    'ignore_paths' => [
        'up',
        'livewire/*',
        'livewire/update',
        'maintenance/status',
        'maintenance/stream',
        'maintenance/bypass',
        '_debugbar/*',
        'sanctum/csrf-cookie',
    ],

    /*
    |--------------------------------------------------------------------------
    | Sensitive Keys (Auto-Redacted)
    |--------------------------------------------------------------------------
    |
    | Values for these keys will be replaced with [redacted] in logs.
    | This ensures sensitive data is never stored in plain text.
    |
    */
    'sensitive_keys' => [
        'password',
        'password_confirmation',
        'current_password',
        'new_password',
        'token',
        '_token',
        'remember_token',
        'api_key',
        'secret',
        'authorization',
        'cookie',
        'credit_card',
        'cvv',
        'ssn',
        'two_factor_secret',
        'two_factor_recovery_codes',
        'private_key',
    ],

    /*
    |--------------------------------------------------------------------------
    | Allowed Headers in Logs
    |--------------------------------------------------------------------------
    |
    | Only these headers will be included in audit logs.
    | All other headers are excluded for security and privacy.
    |
    */
    'header_allowlist' => [
        'accept',
        'content-type',
        'user-agent',
        'referer',
        'origin',
        'x-request-id',
        'x-forwarded-for',
    ],

    /*
    |--------------------------------------------------------------------------
    | Cache Configuration
    |--------------------------------------------------------------------------
    */
    'cache_store' => env('AUDIT_CACHE_STORE', null),
    'last_seen_ttl_seconds' => (int) env('AUDIT_LAST_SEEN_TTL', 300),

    /*
    |--------------------------------------------------------------------------
    | Error Logging
    |--------------------------------------------------------------------------
    */
    'log_errors' => env('AUDIT_LOG_ERRORS', true),
    'error_min_status' => (int) env('AUDIT_ERROR_MIN_STATUS', 400),

    /*
    |--------------------------------------------------------------------------
    | Hash Chain Configuration
    |--------------------------------------------------------------------------
    |
    | The hash chain provides tamper-evident logging. Each log entry
    | includes a hash of its content and the previous entry's hash.
    |
    */
    'verify_chunk' => (int) env('AUDIT_VERIFY_CHUNK', 500),
    'rehash_chunk' => (int) env('AUDIT_REHASH_CHUNK', 500),

    /*
    |--------------------------------------------------------------------------
    | HMAC Signature Configuration
    |--------------------------------------------------------------------------
    |
    | When enabled, each log entry is signed with an HMAC signature
    | for non-repudiation and additional integrity verification.
    |
    */
    'signature_enabled' => env('AUDIT_SIGNATURE_ENABLED', false),
    'signature_secret' => env('AUDIT_SIGNATURE_SECRET', ''),
    'signature_algo' => env('AUDIT_SIGNATURE_ALGO', 'sha256'),

    /*
    |--------------------------------------------------------------------------
    | Retention Policy
    |--------------------------------------------------------------------------
    |
    | Configure how long audit logs should be retained.
    | Set to 0 to disable automatic cleanup.
    |
    */
    'retention_days' => (int) env('AUDIT_RETENTION_DAYS', 365),

    /*
    |--------------------------------------------------------------------------
    | Real-time Configuration
    |--------------------------------------------------------------------------
    |
    | Polling interval for real-time updates in the audit log viewer.
    |
    */
    'poll_interval' => env('AUDIT_POLL_INTERVAL', '30s'),
];
