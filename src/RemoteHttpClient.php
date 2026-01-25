<?php

namespace Laravel\RemoteHttpDatabase;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use Illuminate\Database\QueryException;
use RuntimeException;

class RemoteHttpClient
{
    /**
     * The HTTP client instance.
     *
     * @var \GuzzleHttp\Client
     */
    protected $client;

    /**
     * The endpoint URL.
     *
     * @var string
     */
    protected $endpoint;

    /**
     * The API key.
     *
     * @var string
     */
    protected $apiKey;

    /**
     * The encryption instance.
     *
     * @var \Laravel\RemoteHttpDatabase\RemoteHttpEncryption
     */
    protected $encryption;

    /**
     * The session ID for transaction state.
     *
     * @var string|null
     */
    protected $sessionId = null;

    /**
     * Request timeout in seconds.
     *
     * @var int
     */
    protected $timeout;

    /**
     * Whether to verify SSL certificates.
     *
     * @var bool
     */
    protected $verifySsl;

    /**
     * Number of retry attempts.
     *
     * @var int
     */
    protected $retryAttempts;

    /**
     * Create a new HTTP client instance.
     *
     * @param  string  $endpoint
     * @param  string  $apiKey
     * @return void
     */
    public function __construct($endpoint, $apiKey, RemoteHttpEncryption $encryption, array $options = [])
    {
        $this->endpoint = $endpoint;
        $this->apiKey = $apiKey;
        $this->encryption = $encryption;
        $this->timeout = $options['timeout'] ?? 30;
        $this->verifySsl = $options['verify_ssl'] ?? true;
        $this->retryAttempts = $options['retry_attempts'] ?? 3;

        $this->client = new Client([
            'timeout' => $this->timeout,
            'verify' => $this->verifySsl,
        ]);
    }

    /**
     * Send a query request to the remote endpoint.
     *
     * @param  string  $connectionName
     * @param  string  $query
     * @param  string  $type
     * @return array
     *
     * @throws \Illuminate\Database\QueryException
     */
    public function sendQuery($connectionName, $query, array $bindings = [], $type = 'select')
    {
        $payload = [
            'query' => $query,
            'bindings' => $bindings,
            'type' => $type,
        ];

        if ($this->sessionId) {
            $payload['session_id'] = $this->sessionId;
        }

        $encryptedPayload = $this->encryption->encrypt($payload);

        $attempts = 0;
        $lastException = null;

        while ($attempts < $this->retryAttempts) {
            try {
                $response = $this->client->post($this->endpoint, [
                    'headers' => [
                        'X-API-Key' => $this->apiKey,
                        'Content-Type' => 'application/json',
                    ],
                    'json' => [
                        'data' => $encryptedPayload,
                    ],
                ]);

                $responseData = json_decode($response->getBody()->getContents(), true);

                if (! isset($responseData['data'])) {
                    // Check if this is an unencrypted error response from the endpoint
                    if (isset($responseData['error'])) {
                        throw new QueryException(
                            $connectionName,
                            $query,
                            $bindings,
                            new \Exception($responseData['error'], isset($responseData['code']) ? (int) $responseData['code'] : 0)
                        );
                    }
                    throw new RuntimeException('Invalid response format from remote endpoint.');
                }

                try {
                    $decrypted = $this->encryption->decrypt($responseData['data']);
                } catch (RuntimeException $e) {
                    // If decryption fails, it's a real encryption/decryption error
                    throw new RuntimeException('Decryption failed: '.$e->getMessage(), 0, $e);
                }

                // Validate decrypted data structure
                if (! is_array($decrypted)) {
                    throw new RuntimeException('Invalid decrypted response format.');
                }

                // Store session ID if provided
                if (isset($decrypted['session_id'])) {
                    $this->sessionId = $decrypted['session_id'];
                }

                // Handle errors from the remote database
                if (isset($decrypted['success']) && $decrypted['success'] === false) {
                    $errorCode = isset($decrypted['code']) ? (int) $decrypted['code'] : 0;
                    throw new QueryException(
                        $connectionName,
                        $query,
                        $bindings,
                        new \Exception($decrypted['error'] ?? 'Unknown error', $errorCode)
                    );
                }

                return $decrypted;

            } catch (GuzzleException $e) {
                $lastException = $e;
                $attempts++;

                if ($attempts >= $this->retryAttempts) {
                    $errorCode = is_int($e->getCode()) ? $e->getCode() : 0;
                    throw new QueryException(
                        $connectionName,
                        $query,
                        $bindings,
                        new \Exception('HTTP request failed: '.$e->getMessage(), $errorCode)
                    );
                }

                // Wait before retry (exponential backoff)
                usleep(100000 * $attempts); // 100ms, 200ms, 300ms
            } catch (RuntimeException $e) {
                // Only wrap as encryption/decryption error if it's actually about encryption/decryption
                // If it's already a QueryException or has a SQL error message, re-throw it properly
                if (stripos($e->getMessage(), 'SQLSTATE') !== false || stripos($e->getMessage(), 'SQL') !== false) {
                    // This is likely a SQL error that got wrapped incorrectly
                    $errorCode = is_int($e->getCode()) ? $e->getCode() : 0;
                    throw new QueryException(
                        $connectionName,
                        $query,
                        $bindings,
                        new \Exception($e->getMessage(), $errorCode)
                    );
                }
                // Otherwise, it's a real encryption/decryption error
                $errorCode = is_int($e->getCode()) ? $e->getCode() : 0;
                throw new QueryException(
                    $connectionName,
                    $query,
                    $bindings,
                    new \Exception('Encryption/Decryption error: '.$e->getMessage(), $errorCode)
                );
            }
        }

        throw new QueryException(
            $connectionName,
            $query,
            $bindings,
            new \Exception('Request failed after '.$this->retryAttempts.' attempts', 0)
        );
    }

    /**
     * Send multiple queries in a single batch request.
     *
     * @param  string  $connectionName
     * @param  array  $queries  Array of ['query' => string, 'bindings' => array, 'type' => string]
     * @return array  Array of responses in the same order as queries
     *
     * @throws \Illuminate\Database\QueryException
     */
    public function sendBatch($connectionName, array $queries)
    {
        if (empty($queries)) {
            return [];
        }

        $payload = [
            'batch' => true,
            'queries' => $queries,
        ];

        if ($this->sessionId) {
            $payload['session_id'] = $this->sessionId;
        }

        $encryptedPayload = $this->encryption->encrypt($payload);

        $attempts = 0;
        $lastException = null;

        while ($attempts < $this->retryAttempts) {
            try {
                $response = $this->client->post($this->endpoint, [
                    'headers' => [
                        'X-API-Key' => $this->apiKey,
                        'Content-Type' => 'application/json',
                    ],
                    'json' => [
                        'data' => $encryptedPayload,
                    ],
                ]);

                $responseData = json_decode($response->getBody()->getContents(), true);

                if (! isset($responseData['data'])) {
                    if (isset($responseData['error'])) {
                        throw new QueryException(
                            $connectionName,
                            'BATCH_QUERY',
                            [],
                            new \Exception($responseData['error'], isset($responseData['code']) ? (int) $responseData['code'] : 0)
                        );
                    }
                    throw new RuntimeException('Invalid response format from remote endpoint.');
                }

                try {
                    $decrypted = $this->encryption->decrypt($responseData['data']);
                } catch (RuntimeException $e) {
                    throw new RuntimeException('Decryption failed: '.$e->getMessage(), 0, $e);
                }

                if (! is_array($decrypted)) {
                    throw new RuntimeException('Invalid decrypted response format.');
                }

                // Store session ID if provided
                if (isset($decrypted['session_id'])) {
                    $this->sessionId = $decrypted['session_id'];
                }

                // Handle errors from the remote database
                if (isset($decrypted['success']) && $decrypted['success'] === false) {
                    $errorCode = isset($decrypted['code']) ? (int) $decrypted['code'] : 0;
                    throw new QueryException(
                        $connectionName,
                        'BATCH_QUERY',
                        [],
                        new \Exception($decrypted['error'] ?? 'Unknown error', $errorCode)
                    );
                }

                // Return array of results
                return $decrypted['results'] ?? [];

            } catch (GuzzleException $e) {
                $lastException = $e;
                $attempts++;

                if ($attempts >= $this->retryAttempts) {
                    $errorCode = is_int($e->getCode()) ? $e->getCode() : 0;
                    throw new QueryException(
                        $connectionName,
                        'BATCH_QUERY',
                        [],
                        new \Exception('HTTP request failed: '.$e->getMessage(), $errorCode)
                    );
                }

                usleep(100000 * $attempts);
            } catch (RuntimeException $e) {
                if (stripos($e->getMessage(), 'SQLSTATE') !== false || stripos($e->getMessage(), 'SQL') !== false) {
                    $errorCode = is_int($e->getCode()) ? $e->getCode() : 0;
                    throw new QueryException(
                        $connectionName,
                        'BATCH_QUERY',
                        [],
                        new \Exception($e->getMessage(), $errorCode)
                    );
                }
                $errorCode = is_int($e->getCode()) ? $e->getCode() : 0;
                throw new QueryException(
                    $connectionName,
                    'BATCH_QUERY',
                    [],
                    new \Exception('Encryption/Decryption error: '.$e->getMessage(), $errorCode)
                );
            }
        }

        throw new QueryException(
            $connectionName,
            'BATCH_QUERY',
            [],
            new \Exception('Request failed after '.$this->retryAttempts.' attempts', 0)
        );
    }

    /**
     * Get the current session ID.
     *
     * @return string|null
     */
    public function getSessionId()
    {
        return $this->sessionId;
    }

    /**
     * Set the session ID.
     *
     * @param  string|null  $sessionId
     * @return void
     */
    public function setSessionId($sessionId)
    {
        $this->sessionId = $sessionId;
    }

    /**
     * Clear the session ID.
     *
     * @return void
     */
    public function clearSession()
    {
        $this->sessionId = null;
    }
}
