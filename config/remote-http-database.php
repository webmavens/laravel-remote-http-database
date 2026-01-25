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

    /*
    |--------------------------------------------------------------------------
    | Endpoint Configuration (Server 1 - Has MySQL)
    |--------------------------------------------------------------------------
    |
    | These settings are used when this package is installed on Server 1
    | (the server that has MySQL and serves the endpoint).
    |
    */

    'endpoint_api_key' => env('REMOTE_DB_API_KEY'),

    'endpoint_encryption_key' => env('REMOTE_DB_ENCRYPTION_KEY'),

    'endpoint_path' => env('REMOTE_DB_ENDPOINT_PATH', '/remote-db-endpoint'),

    /*
    |--------------------------------------------------------------------------
    | IP Whitelist (Optional)
    |--------------------------------------------------------------------------
    |
    | If set, only requests from these IP addresses will be allowed.
    | Comma-separated list of IP addresses.
    | Example: "192.168.1.100,10.0.0.50,203.0.113.45"
    |
    */

    'endpoint_allowed_ips' => env('REMOTE_DB_ALLOWED_IPS'),
];
