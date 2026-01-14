<?php

return [
    'catalog' => [
        401 => [
            'title' => 'Authentication required',
            'summary' => 'You need to log in to continue.',
            'why' => [
                'Login session is missing or expired.',
                'The request requires a user identity.',
            ],
            'recovery' => [
                'Log in again, then retry your action.',
                'Use an account with appropriate access.',
            ],
        ],
        403 => [
            'title' => 'Access denied',
            'summary' => 'You do not have permission to open this page.',
            'why' => [
                'Your role does not have the required permission.',
                'Your account is restricted or inactive.',
            ],
            'recovery' => [
                'Log in with an account that has access.',
                'Contact an admin to request permission.',
                'Return to a safe page.',
            ],
        ],
        404 => [
            'title' => 'Page not found',
            'summary' => 'The address you opened is not available.',
            'why' => [
                'The URL is mistyped or no longer valid.',
                'The resource has been moved or deleted.',
            ],
            'recovery' => [
                'Check the URL again.',
                'Return to the home page and use the menu.',
            ],
        ],
        419 => [
            'title' => 'Session expired',
            'summary' => 'Security token is invalid or the session has ended.',
            'why' => [
                'Your session ended due to inactivity.',
                'The form was submitted from an old tab.',
            ],
            'recovery' => [
                'Reload the page, then try again.',
                'Log in again if prompted.',
            ],
        ],
        429 => [
            'title' => 'Too many requests',
            'summary' => 'The server is temporarily limiting requests.',
            'why' => [
                'Too many actions were performed in a short time.',
                'A security rate limit was reached.',
            ],
            'recovery' => [
                'Wait a moment before trying again.',
                'Reduce repeated actions.',
            ],
        ],
        500 => [
            'title' => 'An error occurred',
            'summary' => 'The server encountered an internal problem.',
            'why' => [
                'An unhandled exception occurred.',
                'Configuration or dependencies are misaligned.',
            ],
            'recovery' => [
                'Try reloading the page.',
                'If it persists, contact an admin.',
            ],
        ],
        503 => [
            'title' => 'Service unavailable',
            'summary' => 'The server is under maintenance or overloaded.',
            'why' => [
                'Scheduled maintenance is in progress.',
                'Server capacity is temporarily full.',
            ],
            'recovery' => [
                'Try again in a few minutes.',
                'Check service status if available.',
            ],
        ],
    ],
    'exception_hints' => [
        \Illuminate\Database\QueryException::class => [
            'why' => [
                'Database query failed or schema is not ready.',
            ],
            'recovery' => [
                'Check database connectivity and run migrations.',
            ],
        ],
        \Illuminate\Auth\AuthenticationException::class => [
            'why' => [
                'The session token is not recognized by the server.',
            ],
            'recovery' => [
                'Log in again to create a new session.',
            ],
        ],
        \Symfony\Component\HttpKernel\Exception\NotFoundHttpException::class => [
            'why' => [
                'The route you requested is not registered.',
            ],
            'recovery' => [
                'Use the application navigation menu.',
            ],
        ],
        \Symfony\Component\HttpKernel\Exception\MethodNotAllowedHttpException::class => [
            'why' => [
                'The HTTP method is not allowed for this endpoint.',
            ],
            'recovery' => [
                'Repeat the action from the correct UI.',
            ],
        ],
        \Symfony\Component\HttpKernel\Exception\ServiceUnavailableHttpException::class => [
            'why' => [
                'The server is in maintenance mode or overloaded.',
            ],
            'recovery' => [
                'Try again after a short delay.',
            ],
        ],
        \Illuminate\Session\TokenMismatchException::class => [
            'why' => [
                'Security token does not match the active session.',
            ],
            'recovery' => [
                'Reload the page to generate a new token.',
            ],
        ],
    ],
    'severity' => [
        'critical' => 'Critical Incident',
        'warning' => 'Guarded Response',
        'info' => 'Informational',
    ],
    'labels' => [
        'error' => 'Error',
        'unknown' => 'unknown',
        'na' => 'n/a',
        'severity' => 'Severity',
        'timestamp' => 'Timestamp',
        'request_id' => 'Request ID',
        'path' => 'Path',
        'support' => 'Need help? Contact support.',
        'back_home' => 'Back to home',
        'try_again' => 'Try again',
        'incident_id' => 'Incident ID: :id',
        'severity_chip' => 'Severity',
        'toggle_theme' => 'Toggle theme',
        'back' => 'Back',
        'reload' => 'Reload',
        'login' => 'Login',
        'why_title' => 'Why this happened',
        'recovery_title' => 'Recovery steps',
        'request_preview' => 'Request / Client Preview',
        'status' => 'Status',
        'retry_after' => 'Retry After',
        'copy_request_id' => 'Copy Request ID',
        'advanced_view' => 'Advanced view (developer only).',
        'client_ip' => 'Client IP (observed)',
        'proxy_chain' => 'Proxy chain (trusted)',
        'user_agent' => 'User agent (redacted)',
        'proxy_note' => 'Network IP cannot be determined from standard requests. Proxy chain appears only when trusted reverse proxy is configured.',
        'developer_details' => 'Developer details',
        'exception' => 'Exception',
        'message' => 'Message',
        'location' => 'Location',
    ],
];
