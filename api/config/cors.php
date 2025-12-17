<?php

return [
    'paths' => ['api/*', '*'],
    'allowed_methods' => ['*'],
    'allowed_origins' => [
        'https://app.lacleo.test:3000',
        'https://app.lacleo.test:3001',
        'https://app.lacleo.test',
        'http://localhost:3000',
        'https://local-accounts.lacleo.test',
        'https://local-api.lacleo.test',
    ],
    'allowed_headers' => ['*'],
    'exposed_headers' => [
        'X-Request-ID',
        'request_id',
    ],
    'supports_credentials' => true,
];
