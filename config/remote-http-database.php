<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Remote HTTP Database Configuration
    |--------------------------------------------------------------------------
    |
    | This configuration file is for the remote HTTP database adapter.
    | These settings will be merged with Laravel's database configuration
    | when using the 'remote-http' driver.
    |
    */

    'endpoint' => env('DB_REMOTE_ENDPOINT'),

    'api_key' => env('DB_REMOTE_API_KEY'),

    'encryption_key' => env('DB_REMOTE_ENCRYPTION_KEY'),

    'database' => env('DB_DATABASE', 'laravel'),

    'timeout' => env('DB_REMOTE_TIMEOUT', 30),

    'verify_ssl' => env('DB_REMOTE_VERIFY_SSL', true),

    'retry_attempts' => env('DB_REMOTE_RETRY_ATTEMPTS', 3),
];
