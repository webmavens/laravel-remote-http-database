# Laravel Remote HTTP Database Adapter

A Laravel package that provides a custom database adapter for communicating with a remote MySQL server via HTTP API. This allows you to run a Laravel application on a server without MySQL by connecting to a remote MySQL server through an encrypted HTTP endpoint.

## Features

- 🔒 **Secure**: AES-256-GCM encryption for all data in transit
- 🔑 **Authenticated**: API key-based authentication
- 🔄 **Transaction Support**: Full transaction support with session-based state management
- 🚀 **Drop-in Replacement**: No code changes needed - just change your database connection
- ✅ **Full Compatibility**: Supports all Laravel database features (Eloquent, migrations, transactions, etc.)

## Architecture

```
Server 2 (No MySQL)                    Server 1 (Has MySQL)
┌─────────────────────┐                ┌─────────────────────┐
│ Laravel Application │                │ Remote Endpoint     │
│                     │                │                     │
│  Remote HTTP        │──HTTPS/API───▶│  MySQL Database     │
│  Database Adapter   │                │                     │
└─────────────────────┘                └─────────────────────┘
```

## Requirements

- PHP 8.1 or higher
- Laravel 10.x or 11.x
- Guzzle HTTP Client
- OpenSSL extension (for encryption)

## Installation

### Server 1 (Has MySQL)

1. Install the package via Composer:

```bash
composer require laravel/remote-http-database
```

2. Publish the remote endpoint file:

```bash
php artisan vendor:publish --tag=remote-http-endpoint
```

3. Deploy the endpoint file to a publicly accessible location:

```bash
# The file will be published to: remote-db-endpoint.php
# Move it to your public directory:
mv remote-db-endpoint.php public/db-api.php
```

4. Configure your `.env` file:

```env
# Standard MySQL connection (for the endpoint to use)
DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=your_database
DB_USERNAME=your_username
DB_PASSWORD=your_password

# Remote endpoint security keys
REMOTE_DB_API_KEY=your-secret-api-key-here
REMOTE_DB_ENCRYPTION_KEY=your-32-byte-encryption-key
```

**Important**: The encryption key must be exactly 32 bytes. Generate one using:

```php
bin2hex(random_bytes(16)) // This gives 32 hex characters = 32 bytes
```

Or use:

```bash
php -r "echo bin2hex(random_bytes(16));"
```

5. Ensure the endpoint is accessible via HTTPS (recommended for security).

### Server 2 (No MySQL)

1. Install the package via Composer:

```bash
composer require laravel/remote-http-database
```

2. Publish the configuration file:

```bash
php artisan vendor:publish --tag=remote-http-config
```

3. Update your `.env` file:

```env
DB_CONNECTION=remote-http
DB_REMOTE_ENDPOINT=https://server1.example.com/db-api.php
DB_REMOTE_API_KEY=your-secret-api-key-here
DB_REMOTE_ENCRYPTION_KEY=your-32-byte-encryption-key
DB_DATABASE=your_database_name
DB_REMOTE_TIMEOUT=30
DB_REMOTE_VERIFY_SSL=true
DB_REMOTE_RETRY_ATTEMPTS=3
```

4. (Optional) Add the connection to `config/database.php`:

```php
'connections' => [
    // ... other connections
    
    'remote-http' => [
        'driver' => 'remote-http',
        'endpoint' => env('DB_REMOTE_ENDPOINT'),
        'api_key' => env('DB_REMOTE_API_KEY'),
        'encryption_key' => env('DB_REMOTE_ENCRYPTION_KEY'),
        'database' => env('DB_DATABASE', 'laravel'),
        'timeout' => env('DB_REMOTE_TIMEOUT', 30),
        'verify_ssl' => env('DB_REMOTE_VERIFY_SSL', true),
        'retry_attempts' => env('DB_REMOTE_RETRY_ATTEMPTS', 3),
    ],
],
```

That's it! Your Laravel application will now use the remote MySQL database via HTTP.

## Usage

Once configured, use your Laravel application exactly as you would with a normal MySQL connection:

```php
// Eloquent models work normally
$user = User::find(1);

// Query builder works normally
$users = DB::table('users')->where('active', 1)->get();

// Transactions work normally
DB::transaction(function () {
    User::create(['name' => 'John']);
    Post::create(['title' => 'Hello']);
});

// Migrations work normally
php artisan migrate
```

## Security Considerations

1. **HTTPS**: Always use HTTPS for the remote endpoint URL. Never use HTTP in production.

2. **Strong Keys**: 
   - Use a strong, random API key (at least 32 characters)
   - Use a strong, random 32-byte encryption key
   - Never commit these keys to version control

3. **Firewall**: Consider restricting access to the endpoint via firewall rules (IP whitelist).

4. **Rate Limiting**: Consider implementing rate limiting on the remote endpoint to prevent abuse.

5. **Monitoring**: Monitor endpoint logs for suspicious activity.

## Configuration Options

| Option | Description | Default |
|--------|-------------|---------|
| `endpoint` | The URL of the remote endpoint | Required |
| `api_key` | API key for authentication | Required |
| `encryption_key` | 32-byte encryption key | Required |
| `database` | Database name | `laravel` |
| `timeout` | HTTP request timeout in seconds | `30` |
| `verify_ssl` | Whether to verify SSL certificates | `true` |
| `retry_attempts` | Number of retry attempts on failure | `3` |

## How It Works

1. **Query Execution**: When Laravel executes a database query, the `RemoteHttpConnection` intercepts it.

2. **Encryption**: The query and bindings are encrypted using AES-256-GCM with a unique IV for each request.

3. **HTTP Request**: The encrypted payload is sent to the remote endpoint via HTTPS POST with the API key in headers.

4. **Remote Processing**: The endpoint on Server 1:
   - Validates the API key
   - Decrypts the payload
   - Executes the query on MySQL
   - Maintains transaction state per session
   - Encrypts the response

5. **Response**: The encrypted response is sent back, decrypted, and returned to Laravel as if it came from a local database.

## Transaction Handling

Transactions are handled using session-based state management:

- Each connection gets a unique session ID
- The remote endpoint maintains transaction state per session
- BEGIN/COMMIT/ROLLBACK commands are sent as special transaction queries
- Nested transactions are supported via savepoints

## Troubleshooting

### "Encryption key must be exactly 32 bytes"

Make sure your encryption key is exactly 32 bytes. Generate one using:

```bash
php -r "echo bin2hex(random_bytes(16));"
```

### "HTTP request failed"

- Check that the endpoint URL is correct and accessible
- Verify HTTPS is working (or disable SSL verification for testing only)
- Check firewall rules
- Verify the API key matches on both servers

### "Decryption failed"

- Ensure the encryption key is identical on both Server 1 and Server 2
- Check that the encryption key is exactly 32 bytes

### Transactions not working

- Ensure session files can be written on Server 1 (check temp directory permissions)
- Check that the session ID is being maintained between requests

## Testing

To test the package locally, you can:

1. Set up Server 1 with MySQL and the endpoint
2. Set up Server 2 with the remote-http connection
3. Run your Laravel application tests

## License

This package is open-sourced software licensed under the [MIT license](LICENSE).

## Contributing

Contributions are welcome! Please feel free to submit a Pull Request.

## Support

For issues and questions, please open an issue on GitHub.
