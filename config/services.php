<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Resend, Postmark, AWS, and more. This file provides the de facto
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

    'pdf_bridge' => [
        'driver' => env('PDF_DRIVER', 'auto'),
        'endpoint' => env('PDF_BRIDGE_ENDPOINT'),
        'api_key' => env('PDF_BRIDGE_API_KEY'),
    ],

    'whatsapp' => [
        'driver'                       => env('WHATSAPP_DRIVER', 'gateway'), // 'gateway' or 'official_cloud_api'
        'base_url'                     => env('WHATSAPP_GATEWAY_URL', 'http://13.60.50.153/api/v1'),
        'api_key'                      => env('WHATSAPP_API_KEY', ''),
        'timeout'                      => (int) env('WHATSAPP_TIMEOUT', 15),
        'min_delay_seconds'            => (int) env('WHATSAPP_MIN_DELAY_SECONDS', 8),   // 8-12s safe balanced delay
        'max_delay_seconds'            => (int) env('WHATSAPP_MAX_DELAY_SECONDS', 12),
        'batch_pause_threshold'        => (int) env('WHATSAPP_BATCH_PAUSE_THRESHOLD', 100),
        'batch_pause_duration_seconds' => (int) env('WHATSAPP_BATCH_PAUSE_SECONDS', 300), // 5 min rest after 100 msgs
        'circuit_breaker_failures'     => (int) env('WHATSAPP_CIRCUIT_BREAKER_FAILURES', 5),
        'append_unique_hash'           => env('WHATSAPP_APPEND_UNIQUE_HASH', true),
        // Optional Multi-Instance Sender Pool (e.g. "instance_1_key,instance_2_key,instance_3_key")
        'instances'                    => array_filter(explode(',', (string) env('WHATSAPP_INSTANCES', ''))),
    ],

];
