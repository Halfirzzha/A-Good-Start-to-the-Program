<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'key' => env('POSTMARK_API_KEY'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | OpenAI Configuration
    |--------------------------------------------------------------------------
    |
    | Enterprise AI integration for intelligent monitoring, threat detection,
    | anomaly analysis, and automated responses. Configure API keys and
    | rate limits for production-grade AI-powered security features.
    |
    */

    'openai' => [
        'enabled' => env('OPENAI_ENABLED', false),
        'api_key' => env('OPENAI_API_KEY'),
        'organization' => env('OPENAI_ORGANIZATION'),
        'model' => env('OPENAI_MODEL', 'gpt-4o'),
        'embedding_model' => env('OPENAI_EMBEDDING_MODEL', 'text-embedding-3-small'),
        'max_tokens' => (int) env('OPENAI_MAX_TOKENS', 4096),
        'temperature' => (float) env('OPENAI_TEMPERATURE', 0.3),
        'timeout' => (int) env('OPENAI_TIMEOUT', 30),
        'retry_attempts' => (int) env('OPENAI_RETRY_ATTEMPTS', 3),
        'retry_delay_ms' => (int) env('OPENAI_RETRY_DELAY_MS', 1000),

        // Rate limiting
        'rate_limit' => [
            'requests_per_minute' => (int) env('OPENAI_RATE_LIMIT_RPM', 60),
            'tokens_per_minute' => (int) env('OPENAI_RATE_LIMIT_TPM', 90000),
            'tokens_per_day' => (int) env('OPENAI_RATE_LIMIT_TPD', 1000000),
        ],

        // Feature toggles
        'features' => [
            'security_analysis' => env('OPENAI_FEATURE_SECURITY_ANALYSIS', true),
            'anomaly_detection' => env('OPENAI_FEATURE_ANOMALY_DETECTION', true),
            'threat_classification' => env('OPENAI_FEATURE_THREAT_CLASSIFICATION', true),
            'log_summarization' => env('OPENAI_FEATURE_LOG_SUMMARIZATION', true),
            'smart_alerts' => env('OPENAI_FEATURE_SMART_ALERTS', true),
            'auto_response' => env('OPENAI_FEATURE_AUTO_RESPONSE', false),
        ],

        // Alert triggers
        'alert_triggers' => [
            'high_risk_score' => (int) env('OPENAI_ALERT_HIGH_RISK_SCORE', 8),
            'suspicious_pattern_count' => (int) env('OPENAI_ALERT_SUSPICIOUS_PATTERNS', 5),
            'failed_login_threshold' => (int) env('OPENAI_ALERT_FAILED_LOGINS', 10),
            'anomaly_confidence' => (float) env('OPENAI_ALERT_ANOMALY_CONFIDENCE', 0.85),
        ],
    ],

];
