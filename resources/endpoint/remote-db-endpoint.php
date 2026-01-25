<?php

/**
 * Remote Database Endpoint
 *
 * This file should be deployed on Server 1 (the server with MySQL).
 * Place it in a publicly accessible location (e.g., public/db-api.php)
 * and configure it with your MySQL credentials and security keys.
 *
 * SECURITY: Make sure to:
 * - Use HTTPS
 * - Set strong API_KEY and ENCRYPTION_KEY
 * - Restrict access via firewall/IP whitelist if possible
 * - Monitor logs for suspicious activity
 */

// Load Laravel bootstrap
// When published via 'php artisan vendor:publish', this file will be in the Laravel root
// If placed in public/ directory, adjust the path accordingly
$laravelRoot = __DIR__;

// If file is in public/ directory, go up one level
if (basename(__DIR__) === 'public') {
    $laravelRoot = dirname(__DIR__);
}

if (file_exists($laravelRoot . '/vendor/autoload.php')) {
    require $laravelRoot . '/vendor/autoload.php';
    $app = require_once $laravelRoot . '/bootstrap/app.php';
    $app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();
} else {
    http_response_code(500);
    header('Content-Type: application/json');
    echo json_encode(['error' => 'Laravel bootstrap files not found. Please ensure this file is in the Laravel root or public directory.']);
    exit;
}

// Configuration - Set these in your .env file
$API_KEY = env('REMOTE_DB_API_KEY');
$ENCRYPTION_KEY = env('REMOTE_DB_ENCRYPTION_KEY');

// Validate configuration
if (empty($API_KEY) || empty($ENCRYPTION_KEY)) {
    http_response_code(500);
    header('Content-Type: application/json');
    echo json_encode(['error' => 'Server configuration error']);
    exit;
}

if (strlen($ENCRYPTION_KEY) !== 32) {
    http_response_code(500);
    header('Content-Type: application/json');
    echo json_encode(['error' => 'Encryption key must be 32 bytes']);
    exit;
}

// Only allow POST requests
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    header('Content-Type: application/json');
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

// Validate API key
$headers = getallheaders();
$providedApiKey = $headers['X-API-Key'] ?? null;

if ($providedApiKey !== $API_KEY) {
    http_response_code(401);
    header('Content-Type: application/json');
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

// Get request body
$input = file_get_contents('php://input');
$data = json_decode($input, true);

if (! isset($data['data'])) {
    http_response_code(400);
    header('Content-Type: application/json');
    echo json_encode(['error' => 'Invalid request format']);
    exit;
}

// Decrypt the payload
try {
    $encryption = new \Laravel\RemoteHttpDatabase\RemoteHttpEncryption($ENCRYPTION_KEY);
    $decrypted = $encryption->decrypt($data['data']);
} catch (\Exception $e) {
    http_response_code(400);
    header('Content-Type: application/json');
    echo json_encode(['error' => 'Decryption failed: ' . $e->getMessage()]);
    exit;
}

// Extract request data
$query = $decrypted['query'] ?? '';
$bindings = $decrypted['bindings'] ?? [];
$type = $decrypted['type'] ?? 'select';
$sessionId = $decrypted['session_id'] ?? null;

// Get or create session for transaction state
if (! $sessionId) {
    $sessionId = bin2hex(random_bytes(16));
}

// Initialize session storage (using file-based sessions for simplicity)
// In production, consider using Redis or database sessions
$sessionFile = sys_get_temp_dir() . '/remote_db_session_' . $sessionId . '.json';
$sessionData = [];

if (file_exists($sessionFile)) {
    $sessionData = json_decode(file_get_contents($sessionFile), true) ?: [];
}

// Get database connection
try {
    $db = \Illuminate\Support\Facades\DB::connection();
    $pdo = $db->getPdo();
} catch (\Exception $e) {
    http_response_code(500);
    header('Content-Type: application/json');
    echo json_encode(['error' => 'Database connection failed: ' . $e->getMessage()]);
    exit;
}

// Execute query based on type
$response = [
    'success' => true,
    'session_id' => $sessionId,
];

try {
    switch ($type) {
        case 'transaction':
            // Handle transaction commands
            if (stripos($query, 'BEGIN') === 0 || stripos($query, 'START TRANSACTION') === 0) {
                if (! isset($sessionData['in_transaction']) || ! $sessionData['in_transaction']) {
                    $pdo->beginTransaction();
                    $sessionData['in_transaction'] = true;
                    $sessionData['transaction_level'] = 1;
                } else {
                    // Nested transaction - create savepoint
                    $savepoint = 'sp_' . $sessionData['transaction_level'] + 1;
                    $pdo->exec("SAVEPOINT {$savepoint}");
                    $sessionData['transaction_level']++;
                }
            } elseif (stripos($query, 'COMMIT') === 0) {
                if (isset($sessionData['in_transaction']) && $sessionData['in_transaction']) {
                    if ($sessionData['transaction_level'] <= 1) {
                        $pdo->commit();
                        $sessionData['in_transaction'] = false;
                        $sessionData['transaction_level'] = 0;
                    } else {
                        // Release savepoint
                        $sessionData['transaction_level']--;
                    }
                }
            } elseif (stripos($query, 'ROLLBACK') === 0) {
                if (isset($sessionData['in_transaction']) && $sessionData['in_transaction']) {
                    if ($sessionData['transaction_level'] <= 1) {
                        $pdo->rollBack();
                        $sessionData['in_transaction'] = false;
                        $sessionData['transaction_level'] = 0;
                    } else {
                        // Rollback to savepoint
                        $savepoint = 'sp_' . $sessionData['transaction_level'];
                        $pdo->exec("ROLLBACK TO SAVEPOINT {$savepoint}");
                        $sessionData['transaction_level']--;
                    }
                }
            }
            break;

        case 'select':
            $statement = $pdo->prepare($query);
            $statement->execute($bindings);
            $results = $statement->fetchAll(\PDO::FETCH_ASSOC);
            $response['results'] = $results;
            break;

        case 'insert':
            $statement = $pdo->prepare($query);
            $statement->execute($bindings);
            $response['last_insert_id'] = $pdo->lastInsertId();
            $response['rows_affected'] = $statement->rowCount();
            break;

        case 'affecting':
        case 'statement':
            $statement = $pdo->prepare($query);
            $statement->execute($bindings);
            $response['rows_affected'] = $statement->rowCount();
            break;

        case 'unprepared':
            $rowsAffected = $pdo->exec($query);
            $response['rows_affected'] = $rowsAffected;
            break;

        default:
            throw new \Exception("Unknown query type: {$type}");
    }

} catch (\PDOException $e) {
    $response = [
        'success' => false,
        'error' => $e->getMessage(),
        'code' => $e->getCode(),
        'session_id' => $sessionId,
    ];
} catch (\Exception $e) {
    $response = [
        'success' => false,
        'error' => $e->getMessage(),
        'code' => $e->getCode(),
        'session_id' => $sessionId,
    ];
}

// Save session data
file_put_contents($sessionFile, json_encode($sessionData));

// Clean up old session files (older than 1 hour)
$files = glob(sys_get_temp_dir() . '/remote_db_session_*.json');
foreach ($files as $file) {
    if (filemtime($file) < time() - 3600) {
        @unlink($file);
    }
}

// Encrypt and return response
try {
    $encryptedResponse = $encryption->encrypt($response);
    
    header('Content-Type: application/json');
    echo json_encode(['data' => $encryptedResponse]);
} catch (\Exception $e) {
    http_response_code(500);
    header('Content-Type: application/json');
    echo json_encode(['error' => 'Encryption failed: ' . $e->getMessage()]);
}
