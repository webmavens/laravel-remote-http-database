<?php

namespace Laravel\RemoteHttpDatabase\Connectors;

use Illuminate\Database\Connectors\ConnectorInterface;
use InvalidArgumentException;
use PDO;

class RemoteHttpConnector implements ConnectorInterface
{
    /**
     * Establish a database connection.
     *
     * Since we're using HTTP instead of PDO, we return a mock PDO object
     * that won't actually be used. The RemoteHttpConnection will handle
     * all database operations via HTTP.
     *
     * @param  array  $config
     * @return \PDO
     *
     * @throws \InvalidArgumentException
     */
    public function connect(array $config)
    {
        // Validate required configuration
        if (empty($config['endpoint'])) {
            throw new InvalidArgumentException('Remote HTTP endpoint URL is required.');
        }

        if (empty($config['api_key'])) {
            throw new InvalidArgumentException('API key is required for remote HTTP connection.');
        }

        if (empty($config['encryption_key'])) {
            throw new InvalidArgumentException('Encryption key is required for remote HTTP connection.');
        }

        if (strlen($config['encryption_key']) !== 32) {
            throw new InvalidArgumentException('Encryption key must be exactly 32 bytes for AES-256.');
        }

        // Return a mock PDO object that won't be used
        // The RemoteHttpConnection will override all PDO-dependent methods
        return new class extends PDO {
            public function __construct()
            {
                // Empty constructor - this is a mock PDO
            }

            public function prepare($statement, $options = [])
            {
                throw new \RuntimeException('Direct PDO operations are not supported. Use Laravel query builder.');
            }

            public function exec($statement)
            {
                throw new \RuntimeException('Direct PDO operations are not supported. Use Laravel query builder.');
            }

            public function query($statement, $mode = PDO::ATTR_DEFAULT_FETCH_MODE, $arg3 = null, array $constructorArgs = [])
            {
                throw new \RuntimeException('Direct PDO operations are not supported. Use Laravel query builder.');
            }
        };
    }
}
