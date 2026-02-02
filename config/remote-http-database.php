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
    | Performance Optimization
    |--------------------------------------------------------------------------
    |
    | These settings help optimize performance by reducing HTTP requests.
    |
    */

    'enable_batching' => env('DB_REMOTE_ENABLE_BATCHING', true),

    'enable_caching' => env('DB_REMOTE_ENABLE_CACHING', true),

    'cache_ttl' => env('DB_REMOTE_CACHE_TTL', 60),

    'cache_max_size' => env('DB_REMOTE_CACHE_MAX_SIZE', 1000),

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
    | Endpoint Middleware
    |--------------------------------------------------------------------------
    |
    | Middleware to apply to the remote database endpoint. By default, uses the
    | 'api' middleware group if available, or applies throttle middleware directly.
    |
    | Set to an empty array [] to disable all middleware (not recommended).
    | Set to ['api'] to use the API middleware group.
    | Set to ['throttle:60,1'] to apply rate limiting directly.
    |
    | Example with custom middleware:
    | 'endpoint_middleware' => ['throttle:120,1', 'custom-auth'],
    |
    */

    'endpoint_middleware' => null, // null = use defaults, [] = no middleware

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

    /*
    |--------------------------------------------------------------------------
    | Trusted Proxies for IP Detection
    |--------------------------------------------------------------------------
    |
    | When behind a load balancer or reverse proxy, you need to specify which
    | proxies are trusted to provide the X-Forwarded-For header. Only IPs from
    | trusted proxies will be used for IP whitelist validation.
    | Comma-separated list of trusted proxy IPs, or '*' to trust all (not recommended).
    |
    */

    'trusted_proxies' => env('REMOTE_DB_TRUSTED_PROXIES'),

    /*
    |--------------------------------------------------------------------------
    | Session Storage Driver
    |--------------------------------------------------------------------------
    |
    | The session driver for storing transaction state. Available drivers:
    | - "file": File-based sessions in temp directory (default, simple but not recommended for production)
    | - "redis": Redis-based sessions (recommended for production)
    | - "database": Database-based sessions using Laravel's default connection
    | - "cache": Use Laravel's cache system
    |
    */

    'session_driver' => env('REMOTE_DB_SESSION_DRIVER', 'file'),

    /*
    |--------------------------------------------------------------------------
    | Session Lifetime (in seconds)
    |--------------------------------------------------------------------------
    |
    | How long to keep session data before it expires. This affects transaction
    | state persistence. Default is 1 hour (3600 seconds).
    |
    */

    'session_lifetime' => env('REMOTE_DB_SESSION_LIFETIME', 3600),

    /*
    |--------------------------------------------------------------------------
    | Redis Session Configuration
    |--------------------------------------------------------------------------
    |
    | When using 'redis' session driver, specify the Redis connection to use.
    | This should match a connection defined in your config/database.php redis section.
    |
    */

    'session_redis_connection' => env('REMOTE_DB_SESSION_REDIS_CONNECTION', 'default'),

    /*
    |--------------------------------------------------------------------------
    | Database Session Table
    |--------------------------------------------------------------------------
    |
    | When using 'database' session driver, specify the table name for storing
    | session data. You'll need to create this table with the following schema:
    |
    | CREATE TABLE remote_db_sessions (
    |     id VARCHAR(64) PRIMARY KEY,
    |     data TEXT,
    |     created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    |     updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    | );
    |
    */

    'session_table' => env('REMOTE_DB_SESSION_TABLE', 'remote_db_sessions'),
];
