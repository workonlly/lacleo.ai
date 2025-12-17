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
        'token' => env('POSTMARK_TOKEN'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'resend' => [
        'key' => env('RESEND_KEY'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    'primerole' => [
        'base_url' => env('PRIMEROLE_BASE_URL'),
        'api_auth_token' => env('PRIMEROLE_AUTH_TOKEN'),
        'workspace_token' => env('PRIMEROLE_WORKSPACE_TOKEN'),
        'default_team_name' => env('PRIMEROLE_DEFAUL_TEAM_NAME', 'Primerole'),
    ],

    'stripe' => [
        'secret' => env('STRIPE_SECRET'),
        'webhook_secret' => env('STRIPE_WEBHOOK_SECRET'),
        'price_basic' => env('STRIPE_PRICE_BASIC'),
        'price_pro' => env('STRIPE_PRICE_PRO'),
    ],

    'elastic' => [
        'indices' => [
            'company' => env('ELASTICSEARCH_COMPANY_INDEX', 'local_ollama_company'),
            'contact' => env('ELASTICSEARCH_CONTACT_INDEX', 'local_ollama_contact'),
        ],
    ],

    'ollama' => [
        'base_url' => env('OLLAMA_BASE_URL', 'http://localhost:11434'),
        'chat_model' => env('OLLAMA_CHAT_MODEL', 'tinyllama'),
        'embedding_model' => env('OLLAMA_EMBEDDING_MODEL', 'nomic-embed-text'),
    ],
];
