# Laravel Remote HTTP Database Adapter

A Laravel package that provides a custom database adapter for communicating with a remote MySQL server via HTTP API. This allows you to run a Laravel application on a server without MySQL by connecting to a remote MySQL server through an encrypted HTTP endpoint.

## Quick Start

1. **Install on both servers**: `composer require webmavens/laravel-remote-http-database`
2. **Generate keys** (run once, use on both servers):
   ```bash
   php -r "echo 'REMOTE_DB_API_KEY=' . bin2hex(random_bytes(32)) . PHP_EOL;"
   php -r "echo 'REMOTE_DB_ENCRYPTION_KEY=' . base64_encode(random_bytes(32)) . PHP_EOL;"
   ```
3. **Server 1** (has MySQL): Set `REMOTE_DB_API_KEY` and `REMOTE_DB_ENCRYPTION_KEY` in `.env` (optionally add `REMOTE_DB_ALLOWED_IPS` for IP whitelisting)
4. **Server 2** (client): Set `DB_CONNECTION=remote-http`, `DB_REMOTE_ENDPOINT`, `DB_REMOTE_API_KEY`, and `DB_REMOTE_ENCRYPTION_KEY` in `.env`
5. **Add connection** to `config/database.php` on Server 2 (see Step 4 below)

See the [Installation](#installation) section for detailed steps.

## Features

- 🔒 **Secure**: AES-256-GCM encryption for all data in transit
- 🔑 **Authenticated**: API key-based authentication
- 🛡️ **IP Whitelisting**: Optional IP address whitelisting for additional security
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

- PHP 7.4 or higher (tested up to PHP 8.5+)
- Laravel 10.x, 11.x, or 12.x
- Guzzle HTTP Client
- OpenSSL extension (for encryption)

## Installation

### Step 1: Install the Package

Install the package on both servers via Composer:

```bash
composer require webmavens/laravel-remote-http-database
```

The service provider will be auto-discovered by Laravel, so no manual registration is needed.

### Step 2: Generate Security Keys

Generate a secure API key and encryption key. These must be **identical** on both servers:

```bash
# Generate a 32-byte encryption key (base64 encoded)
php -r "echo base64_encode(random_bytes(32));"

# Generate a secure API key (hex encoded, 64 characters)
php -r "echo bin2hex(random_bytes(32));"
```

Save these values - you'll need them for both servers.

### Step 3: Configure Server 1 (Has MySQL)

Server 1 hosts the MySQL database and serves the remote endpoint.

1. **Configure your `.env` file** with your MySQL connection and security keys:

```env
# Standard MySQL connection (for the endpoint to use)
DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=your_database
DB_USERNAME=your_username
DB_PASSWORD=your_password

# Remote endpoint security keys (MUST match Server 2)
REMOTE_DB_API_KEY=your-generated-api-key-here
REMOTE_DB_ENCRYPTION_KEY=your-generated-encryption-key-here

# Optional: IP whitelist (comma-separated IP addresses)
# Only requests from these IPs will be allowed. Leave empty to allow all IPs.
REMOTE_DB_ALLOWED_IPS=192.168.1.100,10.0.0.50,203.0.113.45
```

**Important Notes:**
- The `REMOTE_DB_API_KEY` can be any secure string (recommended: 64+ characters)
- The `REMOTE_DB_ENCRYPTION_KEY` must be exactly 32 bytes when decoded. You can store it as:
  - Raw 32-byte string, OR
  - Base64-encoded string (44 characters) - the package will auto-detect and decode it
- **IP Whitelisting**: The `REMOTE_DB_ALLOWED_IPS` is optional. If set, only requests from the specified IP addresses will be allowed. Requests from other IPs will be rejected with a `403 Forbidden` response before any processing occurs. This provides an additional security layer.

2. **The endpoint route is automatically registered** at `/remote-db-endpoint` when `REMOTE_DB_API_KEY` is set.

3. **Test the endpoint** (optional):

```bash
curl http://localhost:8000/remote-db-endpoint
```

You should see a JSON response with endpoint information.

### Step 4: Configure Server 2 (No MySQL - Client)

Server 2 connects to Server 1's endpoint to access the database.

1. **Add the remote-http connection to `config/database.php`**:

```php
'connections' => [
    // ... other connections
    
    'remote-http' => [
        'driver' => 'remote-http',
        'endpoint' => env('DB_REMOTE_ENDPOINT'),
        'api_key' => env('DB_REMOTE_API_KEY'),
        'encryption_key' => base64_decode(env('DB_REMOTE_ENCRYPTION_KEY'), true) ?: env('DB_REMOTE_ENCRYPTION_KEY'),
        'database' => env('DB_DATABASE', 'laravel'),
        'timeout' => env('DB_REMOTE_TIMEOUT', 30),
        'verify_ssl' => env('DB_REMOTE_VERIFY_SSL', true),
        'retry_attempts' => env('DB_REMOTE_RETRY_ATTEMPTS', 3),
        'prefix' => '',
        'prefix_indexes' => true,
    ],
],
```

2. **Configure your `.env` file**:

```env
# Use remote-http as the default connection
DB_CONNECTION=remote-http

# Remote HTTP Database Configuration
DB_REMOTE_ENDPOINT=http://server1.example.com/remote-db-endpoint
DB_REMOTE_API_KEY=your-generated-api-key-here
DB_REMOTE_ENCRYPTION_KEY=your-generated-encryption-key-here
DB_DATABASE=your_database_name
DB_REMOTE_TIMEOUT=30
DB_REMOTE_VERIFY_SSL=true
DB_REMOTE_RETRY_ATTEMPTS=3
```

**Critical**: The `DB_REMOTE_API_KEY` and `DB_REMOTE_ENCRYPTION_KEY` must **exactly match** the values on Server 1.

3. **For local development**, you can disable SSL verification:

```env
DB_REMOTE_VERIFY_SSL=false
```

**⚠️ Never disable SSL verification in production!**

### Step 5: Test the Connection

Test the connection from Server 2:

```bash
php artisan tinker
```

```php
DB::connection()->select('SELECT 1 as test');
```

Or create a test script:

```php
<?php
require __DIR__.'/vendor/autoload.php';

$app = require_once __DIR__.'/bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

try {
    $result = DB::connection()->select('SELECT 1 as test');
    echo "✓ Connection successful!\n";
} catch (\Exception $e) {
    echo "✗ Connection failed: " . $e->getMessage() . "\n";
}
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

3. **IP Whitelisting**: Use the `REMOTE_DB_ALLOWED_IPS` environment variable to restrict access to specific IP addresses. This provides an additional layer of security by rejecting requests from unauthorized IPs early in the request lifecycle. See the [IP Whitelisting](#ip-whitelisting) section for detailed instructions.

4. **Firewall**: Consider restricting access to the endpoint via firewall rules (IP whitelist).

5. **Rate Limiting**: Consider implementing rate limiting on the remote endpoint to prevent abuse.

6. **Monitoring**: Monitor endpoint logs for suspicious activity.

## Configuration Options

### Client Configuration (Server 2)

| Option | Environment Variable | Description | Default |
|--------|---------------------|-------------|---------|
| `endpoint` | `DB_REMOTE_ENDPOINT` | The URL of the remote endpoint | Required |
| `api_key` | `DB_REMOTE_API_KEY` | API key for authentication (must match Server 1) | Required |
| `encryption_key` | `DB_REMOTE_ENCRYPTION_KEY` | 32-byte encryption key (can be base64 encoded) | Required |
| `database` | `DB_DATABASE` | Database name | `laravel` |
| `timeout` | `DB_REMOTE_TIMEOUT` | HTTP request timeout in seconds | `30` |
| `verify_ssl` | `DB_REMOTE_VERIFY_SSL` | Whether to verify SSL certificates | `true` |
| `retry_attempts` | `DB_REMOTE_RETRY_ATTEMPTS` | Number of retry attempts on failure | `3` |

### Server Configuration (Server 1)

| Option | Environment Variable | Description |
|--------|---------------------|-------------|
| `endpoint_api_key` | `REMOTE_DB_API_KEY` | API key for endpoint authentication |
| `endpoint_encryption_key` | `REMOTE_DB_ENCRYPTION_KEY` | 32-byte encryption key (can be base64 encoded) |
| `endpoint_path` | `REMOTE_DB_ENDPOINT_PATH` | Custom endpoint path (optional) | `/remote-db-endpoint` |
| `endpoint_allowed_ips` | `REMOTE_DB_ALLOWED_IPS` | Comma-separated list of allowed IP addresses (optional) | `null` |

## How It Works

1. **Query Execution**: When Laravel executes a database query, the `RemoteHttpConnection` intercepts it.

2. **Encryption**: The query and bindings are encrypted using AES-256-GCM with a unique IV for each request.

3. **HTTP Request**: The encrypted payload is sent to the remote endpoint via HTTPS POST with the API key in headers.

4. **Remote Processing**: The endpoint on Server 1:
   - Validates IP address (if whitelisting is enabled)
   - Validates the API key
   - Decrypts the payload
   - Executes the query on MySQL
   - Maintains transaction state per session
   - Encrypts the response

5. **Response**: The encrypted response is sent back, decrypted, and returned to Laravel as if it came from a local database.

## IP Whitelisting

The package supports optional IP address whitelisting to restrict access to the remote database endpoint. When enabled, only requests from specified IP addresses will be allowed.

### How to Enable IP Whitelisting

1. **Add IP addresses to your `.env` file on Server 1**:

```env
# Comma-separated list of allowed IP addresses
REMOTE_DB_ALLOWED_IPS=192.168.1.100,10.0.0.50,203.0.113.45
```

2. **Clear the configuration cache**:

```bash
php artisan config:clear
```

3. **Restart your application** to apply the changes.

### How It Works

- IP validation occurs **early** in the request lifecycle, before any decryption or database operations
- Requests from non-whitelisted IPs receive a `403 Forbidden` response immediately
- The package automatically handles proxy headers (`X-Forwarded-For`, `X-Real-IP`) to correctly identify the client IP when behind load balancers or reverse proxies
- If `REMOTE_DB_ALLOWED_IPS` is not set or empty, all IP addresses are allowed (default behavior)

### Examples

**Single IP address:**
```env
REMOTE_DB_ALLOWED_IPS=192.168.1.100
```

**Multiple IP addresses:**
```env
REMOTE_DB_ALLOWED_IPS=192.168.1.100,10.0.0.50,203.0.113.45
```

**Behind a proxy/load balancer:**
The package automatically detects the real client IP from `X-Forwarded-For` or `X-Real-IP` headers. Just whitelist the actual client IPs:

```env
REMOTE_DB_ALLOWED_IPS=203.0.113.45,198.51.100.10
```

### Important Notes

- **IP addresses must match exactly** - the package performs exact string matching
- **No CIDR notation support** - you must list each IP address individually
- **Proxy-aware** - the package correctly identifies client IPs even when behind proxies
- **Optional feature** - if not set, all IPs are allowed (backward compatible)

## Transaction Handling

Transactions are handled using session-based state management:

- Each connection gets a unique session ID
- The remote endpoint maintains transaction state per session
- BEGIN/COMMIT/ROLLBACK commands are sent as special transaction queries
- Nested transactions are supported via savepoints

## Troubleshooting

### "401 Unauthorized" Error

This means the API key doesn't match between servers:

1. **Verify the API keys match exactly** on both Server 1 and Server 2:
   - Server 1: `REMOTE_DB_API_KEY` in `.env`
   - Server 2: `DB_REMOTE_API_KEY` in `.env`
   
2. **Clear config cache** on both servers:
   ```bash
   php artisan config:clear
   ```

3. **Restart your application** after changing environment variables

### "Encryption key must be exactly 32 bytes"

The encryption key must be exactly 32 bytes when decoded. You can:

1. **Use a base64-encoded key** (recommended - 44 characters):
   ```bash
   php -r "echo base64_encode(random_bytes(32));"
   ```
   Store this in your `.env` file as-is.

2. **Use a raw 32-byte key** (32 characters):
   ```bash
   php -r "echo bin2hex(random_bytes(16));"
   ```
   This generates 32 hex characters = 32 bytes.

3. **Verify the key matches** on both servers exactly (including any base64 encoding)

### "HTTP request failed"

- Check that the endpoint URL is correct and accessible
- Verify the endpoint is reachable: `curl http://server1.example.com/remote-db-endpoint`
- Check firewall rules on Server 1
- Verify the API key matches on both servers
- For local development, ensure `DB_REMOTE_VERIFY_SSL=false` if using HTTP

### "Decryption failed"

- Ensure the encryption key is **identical** on both Server 1 and Server 2
- If using base64 encoding, ensure both servers use the same format
- Clear config cache: `php artisan config:clear`

### "QueryException: Argument #3 ($bindings) must be of type array"

This error indicates you're using Laravel 12. Make sure you have the latest version of this package that supports Laravel 12's `QueryException` constructor signature.

### Transactions not working

- Ensure session files can be written on Server 1 (check temp directory permissions)
- Check that the session ID is being maintained between requests
- Verify transaction state is being preserved in the session storage

### Endpoint not found (404)

- Ensure `REMOTE_DB_API_KEY` is set in Server 1's `.env` file
- The route is only registered when the API key is present
- Clear config cache: `php artisan config:clear`

### "403 Forbidden: IP address not allowed"

This error occurs when IP whitelisting is enabled and the request is coming from an unauthorized IP address.

1. **Check the client IP address**:
   - Verify the IP address of Server 2 (the client making requests)
   - If behind a proxy/load balancer, check the `X-Forwarded-For` or `X-Real-IP` headers

2. **Add the IP to the whitelist** on Server 1:
   ```env
   REMOTE_DB_ALLOWED_IPS=192.168.1.100,10.0.0.50
   ```
   Add the client's IP address to the comma-separated list.

3. **Clear config cache** on Server 1:
   ```bash
   php artisan config:clear
   ```

4. **Temporarily disable IP whitelisting** (for testing):
   - Remove or comment out `REMOTE_DB_ALLOWED_IPS` in Server 1's `.env` file
   - Clear config cache and restart the application

5. **Verify proxy configuration**:
   - If Server 1 is behind a proxy/load balancer, ensure it's properly forwarding client IP headers
   - The package checks `X-Forwarded-For` and `X-Real-IP` headers automatically

## Testing

### Local Development Setup

For local development, you can run both servers on different ports:

**Terminal 1 - Server 1 (MySQL Server):**
```bash
# In your Server 1 project directory
php artisan serve --port=8000
```

**Terminal 2 - Server 2 (Client):**
```bash
# In your Server 2 project directory
php artisan serve --port=8001
```

Make sure:
- Server 1 has MySQL running and `REMOTE_DB_API_KEY` set
- Server 2 has `DB_REMOTE_ENDPOINT=http://localhost:8000/remote-db-endpoint`
- Both have matching API keys and encryption keys

### Running Tests

The package includes tests that can be run with:

```bash
composer test
```

Or using PHPUnit directly:

```bash
vendor/bin/phpunit
```

## License

This package is open-sourced software licensed under the [MIT license](LICENSE).

## Contributing

Contributions are welcome! Please feel free to submit a Pull Request.

## Support

For issues and questions, please open an issue on GitHub.
