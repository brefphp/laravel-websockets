<?php

declare(strict_types=1);

return [

    // The API Gateway WebSocket API
    'api_id' => env('BREF_WEBSOCKET_API_ID'),
    'stage' => env('BREF_WEBSOCKET_STAGE'),
    'region' => env('AWS_REGION', env('AWS_DEFAULT_REGION')),

    // Where channel subscriptions are stored: "database" or "dynamodb"
    'store' => env('BREF_WEBSOCKET_STORE', 'database'),

    'database' => [
        // The application's default connection when null
        'connection' => env('BREF_WEBSOCKET_DB_CONNECTION'),
        'table' => 'websocket_subscriptions',
    ],

    'dynamodb' => [
        'table' => env('BREF_WEBSOCKET_DYNAMODB_TABLE'),
        // For tests against DynamoDB Local
        'endpoint' => env('BREF_WEBSOCKET_DYNAMODB_ENDPOINT'),
    ],

    // Hosts that can open a connection, with wildcards, e.g. "example.com,*.example.com"
    'allowed_origins' => explode(',', (string) env('BREF_WEBSOCKET_ALLOWED_ORIGINS', '*')),

];
