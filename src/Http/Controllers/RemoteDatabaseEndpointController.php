<?php

namespace Laravel\RemoteHttpDatabase\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Laravel\RemoteHttpDatabase\RemoteHttpEncryption;
use Laravel\RemoteHttpDatabase\Session\SessionStorageFactory;
use Laravel\RemoteHttpDatabase\Session\SessionStorageInterface;

class RemoteDatabaseEndpointController
{
    /**
     * The session storage instance.
     *
     * @var \Laravel\RemoteHttpDatabase\Session\SessionStorageInterface|null
     */
    protected $sessionStorage;

    /**
     * Handle remote database requests.
     */
    public function __invoke(Request $request): JsonResponse
    {
        // Configuration - Get from config only (avoid env() for cached config compatibility)
        $apiKey = config('remote-http-database.endpoint_api_key');
        $encryptionKey = config('remote-http-database.endpoint_encryption_key');

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
        $allowedIps = config('remote-http-database.endpoint_allowed_ips');
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

        // Validate API key using constant-time comparison to prevent timing attacks
        $providedApiKey = $request->header('X-API-Key');

        if (! hash_equals($apiKey, $providedApiKey ?? '')) {
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

        // Check if this is a batch request
        $isBatch = isset($decrypted['batch']) && $decrypted['batch'] === true;
        $sessionId = $decrypted['session_id'] ?? null;

        // Get or create session for transaction state
        if (! $sessionId) {
            $sessionId = bin2hex(random_bytes(16));
        }

        // Initialize session storage using configured driver
        $sessionStorage = $this->getSessionStorage();
        $sessionData = $sessionStorage->get($sessionId);

        // Get database connection
        try {
            $db = DB::connection();
            $pdo = $db->getPdo();
        } catch (\Exception $e) {
            return response()->json(['error' => 'Database connection failed: '.$e->getMessage()], 500);
        }

        // Handle batch requests
        if ($isBatch) {
            $queries = $decrypted['queries'] ?? [];
            $results = [];

            foreach ($queries as $queryData) {
                $query = $queryData['query'] ?? '';
                $bindings = $queryData['bindings'] ?? [];
                $type = $queryData['type'] ?? 'select';

                try {
                    $result = $this->executeQuery($pdo, $query, $bindings, $type, $sessionData);
                    $results[] = $result;
                } catch (\PDOException $e) {
                    if (stripos($e->getMessage(), 'no active transaction') !== false) {
                        $sessionData['in_transaction'] = false;
                        $sessionData['transaction_level'] = 0;
                        $results[] = ['success' => true];
                    } else {
                        $results[] = [
                            'success' => false,
                            'error' => $e->getMessage(),
                            'code' => $e->getCode(),
                        ];
                    }
                } catch (\Exception $e) {
                    $results[] = [
                        'success' => false,
                        'error' => $e->getMessage(),
                        'code' => $e->getCode(),
                    ];
                }
            }

            $response = [
                'success' => true,
                'session_id' => $sessionId,
                'results' => $results,
            ];
        } else {
            // Single query request (backward compatible)
            $query = $decrypted['query'] ?? '';
            $bindings = $decrypted['bindings'] ?? [];
            $type = $decrypted['type'] ?? 'select';

            // Execute query based on type
            $response = [
                'success' => true,
                'session_id' => $sessionId,
            ];

            try {
                $result = $this->executeQuery($pdo, $query, $bindings, $type, $sessionData);
                $response = array_merge($response, $result);
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
        }

        // Save session data using configured storage driver
        $sessionStorage->save($sessionId, $sessionData);

        // Cleanup expired sessions (handled by storage driver)
        $sessionStorage->cleanup();

        // Encrypt and return response
        try {
            $encryptedResponse = $encryption->encrypt($response);

            return response()->json(['data' => $encryptedResponse]);
        } catch (\Exception $e) {
            return response()->json(['error' => 'Encryption failed: '.$e->getMessage()], 500);
        }
    }

    /**
     * Get the session storage instance.
     *
     * @return \Laravel\RemoteHttpDatabase\Session\SessionStorageInterface
     */
    protected function getSessionStorage(): SessionStorageInterface
    {
        if ($this->sessionStorage !== null) {
            return $this->sessionStorage;
        }

        $driver = config('remote-http-database.session_driver', 'file');
        $lifetime = config('remote-http-database.session_lifetime', 3600);

        $config = [
            'lifetime' => $lifetime,
            'connection' => config('remote-http-database.session_redis_connection', 'default'),
            'table' => config('remote-http-database.session_table', 'remote_db_sessions'),
        ];

        $this->sessionStorage = SessionStorageFactory::create($driver, $config);

        return $this->sessionStorage;
    }

    /**
     * Execute a single query and return the result.
     *
     * @param  \PDO  $pdo
     * @param  string  $query
     * @param  array  $bindings
     * @param  string  $type
     * @param  array  &$sessionData
     * @return array
     */
    protected function executeQuery($pdo, $query, $bindings, $type, &$sessionData)
    {
        $result = [];

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
                $result['success'] = true;
                break;

            case 'select':
                $statement = $pdo->prepare($query);
                $statement->execute($bindings);
                $results = $statement->fetchAll(\PDO::FETCH_ASSOC);
                $result['results'] = $results;
                break;

            case 'insert':
                $statement = $pdo->prepare($query);
                $statement->execute($bindings);
                $result['last_insert_id'] = $pdo->lastInsertId();
                $result['rows_affected'] = $statement->rowCount();
                break;

            case 'affecting':
            case 'statement':
                $statement = $pdo->prepare($query);
                $statement->execute($bindings);
                $result['rows_affected'] = $statement->rowCount();
                break;

            case 'unprepared':
                $rowsAffected = $pdo->exec($query);
                $result['rows_affected'] = $rowsAffected;
                break;

            default:
                throw new \Exception("Unknown query type: {$type}");
        }

        return $result;
    }

    /**
     * Get the client's IP address, handling proxies correctly.
     *
     * Only trusts X-Forwarded-For and X-Real-IP headers when the request
     * comes from a configured trusted proxy to prevent IP spoofing attacks.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return string
     */
    protected function getClientIp(Request $request): string
    {
        $directIp = $request->ip();
        $trustedProxies = config('remote-http-database.trusted_proxies');

        // If no trusted proxies are configured, only use direct IP
        // This prevents IP spoofing via X-Forwarded-For header
        if (empty($trustedProxies)) {
            return $directIp;
        }

        // Check if the direct connection is from a trusted proxy
        $trustedProxyList = array_map('trim', explode(',', $trustedProxies));
        $isTrustedProxy = $trustedProxies === '*' || in_array($directIp, $trustedProxyList);

        if (! $isTrustedProxy) {
            // Request is not from a trusted proxy, ignore forwarded headers
            return $directIp;
        }

        // Request is from a trusted proxy, we can trust the forwarded headers
        // Check for IP in X-Forwarded-For header
        $forwardedFor = $request->header('X-Forwarded-For');
        if ($forwardedFor) {
            // X-Forwarded-For contains a chain: client, proxy1, proxy2, ...
            // The rightmost untrusted IP is the actual client
            $ips = array_map('trim', explode(',', $forwardedFor));

            // Walk backwards through the chain to find the first untrusted IP
            // This is the actual client IP
            for ($i = count($ips) - 1; $i >= 0; $i--) {
                $ip = $ips[$i];
                if (! filter_var($ip, FILTER_VALIDATE_IP)) {
                    continue;
                }

                // If this IP is not a trusted proxy, it's the client
                if ($trustedProxies !== '*' && ! in_array($ip, $trustedProxyList)) {
                    return $ip;
                }
            }

            // If all IPs in the chain are trusted proxies, use the leftmost one
            $firstIp = trim($ips[0]);
            if (filter_var($firstIp, FILTER_VALIDATE_IP)) {
                return $firstIp;
            }
        }

        // Check for IP in X-Real-IP header (simpler, set by nginx/other proxies)
        $realIp = $request->header('X-Real-IP');
        if ($realIp && filter_var($realIp, FILTER_VALIDATE_IP)) {
            return $realIp;
        }

        // Fall back to direct connection IP
        return $directIp;
    }
}
