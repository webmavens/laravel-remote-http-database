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
     * @param  \Laravel\RemoteHttpDatabase\RemoteHttpEncryption  $encryption
     * @param  array  $options
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
     * @param  string  $query
     * @param  array  $bindings
     * @param  string  $type
     * @return array
     *
     * @throws \Illuminate\Database\QueryException
     */
    public function sendQuery($query, array $bindings = [], $type = 'select')
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
                    throw new RuntimeException('Invalid response format from remote endpoint.');
                }

                $decrypted = $this->encryption->decrypt($responseData['data']);

                // Store session ID if provided
                if (isset($decrypted['session_id'])) {
                    $this->sessionId = $decrypted['session_id'];
                }

                // Handle errors
                if (isset($decrypted['success']) && $decrypted['success'] === false) {
                    throw new QueryException(
                        $query,
                        $bindings,
                        new \Exception($decrypted['error'] ?? 'Unknown error', $decrypted['code'] ?? 0)
                    );
                }

                return $decrypted;

            } catch (GuzzleException $e) {
                $lastException = $e;
                $attempts++;

                if ($attempts >= $this->retryAttempts) {
                    throw new QueryException(
                        $query,
                        $bindings,
                        new \Exception('HTTP request failed: ' . $e->getMessage(), $e->getCode())
                    );
                }

                // Wait before retry (exponential backoff)
                usleep(100000 * $attempts); // 100ms, 200ms, 300ms
            } catch (RuntimeException $e) {
                throw new QueryException(
                    $query,
                    $bindings,
                    new \Exception('Encryption/Decryption error: ' . $e->getMessage(), $e->getCode())
                );
            }
        }

        throw new QueryException(
            $query,
            $bindings,
            new \Exception('Request failed after ' . $this->retryAttempts . ' attempts', 0)
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
