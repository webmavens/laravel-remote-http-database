<?php

namespace Laravel\RemoteHttpDatabase\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Laravel\RemoteHttpDatabase\RemoteHttpEncryption;

class RemoteDatabaseEndpointController
{
    /**
     * Handle remote database requests.
     */
    public function __invoke(Request $request): JsonResponse
    {
        // Configuration - Get from config or env
        $apiKey = config('remote-http-database.endpoint_api_key') ?? env('REMOTE_DB_API_KEY');
        $encryptionKey = config('remote-http-database.endpoint_encryption_key') ?? env('REMOTE_DB_ENCRYPTION_KEY');

        // Validate configuration
        if (empty($apiKey) || empty($encryptionKey)) {
            return response()->json(['error' => 'Server configuration error'], 500);
        }

        // Decode base64 encoded key if needed (keys can be stored as base64 in env)
        $decodedKey = base64_decode($encryptionKey, true);
        if ($decodedKey !== false && strlen($decodedKey) === 32) {
            $encryptionKey = $decodedKey;
        }

        if (strlen($encryptionKey) !== 32) {
            return response()->json(['error' => 'Encryption key must be 32 bytes'], 500);
        }

        // Validate IP whitelist if configured
        $allowedIps = config('remote-http-database.endpoint_allowed_ips') ?? env('REMOTE_DB_ALLOWED_IPS');
        if (! empty($allowedIps)) {
            $clientIp = $this->getClientIp($request);
            $allowedIpList = array_map('trim', explode(',', $allowedIps));
            
            if (! in_array($clientIp, $allowedIpList)) {
                return response()->json(['error' => 'Forbidden: IP address not allowed'], 403);
            }
        }

        // Handle GET requests with informational message
        if ($request->isMethod('GET')) {
            return response()->json([
                'message' => 'Remote HTTP Database Endpoint',
                'status' => 'active',
                'method' => 'POST',
                'required_headers' => [
                    'X-API-Key' => 'Your API key',
                    'Content-Type' => 'application/json',
                ],
            ]);
        }

        // Only allow POST requests for actual database operations
        if (! $request->isMethod('POST')) {
            return response()->json(['error' => 'Method not allowed'], 405);
        }

        // Validate API key
        $providedApiKey = $request->header('X-API-Key');

        if ($providedApiKey !== $apiKey) {
            return response()->json(['error' => 'Unauthorized'], 401);
        }

        // Get request body
        $data = $request->json()->all();

        if (! isset($data['data'])) {
            return response()->json(['error' => 'Invalid request format'], 400);
        }

        // Decrypt the payload
        try {
            $encryption = new RemoteHttpEncryption($encryptionKey);
            $decrypted = $encryption->decrypt($data['data']);
        } catch (\Exception $e) {
            return response()->json(['error' => 'Decryption failed: '.$e->getMessage()], 400);
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
        $sessionFile = sys_get_temp_dir().'/remote_db_session_'.$sessionId.'.json';
        $sessionData = [];

        if (file_exists($sessionFile)) {
            $sessionData = json_decode(file_get_contents($sessionFile), true) ?: [];
        }

        // Get database connection
        try {
            $db = DB::connection();
            $pdo = $db->getPdo();
        } catch (\Exception $e) {
            return response()->json(['error' => 'Database connection failed: '.$e->getMessage()], 500);
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
                        // Check actual PDO state, not just session data
                        if (! $pdo->inTransaction()) {
                            $pdo->beginTransaction();
                            $sessionData['in_transaction'] = true;
                            $sessionData['transaction_level'] = 1;
                        } else {
                            // Nested transaction - create savepoint
                            $savepoint = 'sp_'.($sessionData['transaction_level'] + 1);
                            $pdo->exec("SAVEPOINT {$savepoint}");
                            $sessionData['transaction_level']++;
                        }
                    } elseif (stripos($query, 'COMMIT') === 0) {
                        // Check actual PDO state before committing
                        if ($pdo->inTransaction()) {
                            if (! isset($sessionData['transaction_level']) || $sessionData['transaction_level'] <= 1) {
                                $pdo->commit();
                                $sessionData['in_transaction'] = false;
                                $sessionData['transaction_level'] = 0;
                            } else {
                                // Release savepoint
                                $sessionData['transaction_level']--;
                            }
                        } else {
                            // No active transaction - sync session state
                            $sessionData['in_transaction'] = false;
                            $sessionData['transaction_level'] = 0;
                        }
                    } elseif (stripos($query, 'ROLLBACK') === 0) {
                        // Check actual PDO state before rolling back
                        if ($pdo->inTransaction()) {
                            if (! isset($sessionData['transaction_level']) || $sessionData['transaction_level'] <= 1) {
                                $pdo->rollBack();
                                $sessionData['in_transaction'] = false;
                                $sessionData['transaction_level'] = 0;
                            } else {
                                // Rollback to savepoint
                                $savepoint = 'sp_'.$sessionData['transaction_level'];
                                $pdo->exec("ROLLBACK TO SAVEPOINT {$savepoint}");
                                $sessionData['transaction_level']--;
                            }
                        } else {
                            // No active transaction - sync session state
                            $sessionData['in_transaction'] = false;
                            $sessionData['transaction_level'] = 0;
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
            // Handle transaction-related errors gracefully
            if (stripos($e->getMessage(), 'no active transaction') !== false) {
                // Sync session state when transaction error occurs
                $sessionData['in_transaction'] = false;
                $sessionData['transaction_level'] = 0;
                // Don't treat this as a fatal error - just sync state
                $response['success'] = true;
            } else {
                $response = [
                    'success' => false,
                    'error' => $e->getMessage(),
                    'code' => $e->getCode(),
                    'session_id' => $sessionId,
                ];
            }
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
        $files = glob(sys_get_temp_dir().'/remote_db_session_*.json');
        foreach ($files as $file) {
            if (filemtime($file) < time() - 3600) {
                @unlink($file);
            }
        }

        // Encrypt and return response
        try {
            $encryptedResponse = $encryption->encrypt($response);

            return response()->json(['data' => $encryptedResponse]);
        } catch (\Exception $e) {
            return response()->json(['error' => 'Encryption failed: '.$e->getMessage()], 500);
        }
    }

    /**
     * Get the client's IP address, handling proxies correctly.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return string
     */
    protected function getClientIp(Request $request): string
    {
        // Check for IP in X-Forwarded-For header (when behind proxy/load balancer)
        $forwardedFor = $request->header('X-Forwarded-For');
        if ($forwardedFor) {
            // X-Forwarded-For can contain multiple IPs, take the first one
            $ips = explode(',', $forwardedFor);
            $ip = trim($ips[0]);
            if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
                return $ip;
            }
        }

        // Check for IP in X-Real-IP header
        $realIp = $request->header('X-Real-IP');
        if ($realIp && filter_var($realIp, FILTER_VALIDATE_IP)) {
            return $realIp;
        }

        // Fall back to request IP
        return $request->ip();
    }
}
