<?php

namespace Laravel\RemoteHttpDatabase\Connectors;

use Illuminate\Database\Connectors\ConnectorInterface;
use InvalidArgumentException;
use PDO;
use PDOStatement;

class RemoteHttpConnector implements ConnectorInterface
{
    /**
     * Establish a database connection.
     *
     * Since we're using HTTP instead of PDO, we return a mock PDO object
     * that won't actually be used. The RemoteHttpConnection will handle
     * all database operations via HTTP.
     *
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
        // Initialize with in-memory SQLite to make PDO properly initialized
        return new class extends PDO
        {
            /** @var bool */
            private $transactionState = false;

            public function __construct()
            {
                // Initialize with in-memory SQLite to make PDO properly initialized
                // This won't be used for actual operations
                parent::__construct('sqlite::memory:');
            }

            /**
             * @param string $query
             * @param array $options
             * @return PDOStatement|false
             */
            public function prepare($query, $options = [])
            {
                throw new \RuntimeException('Direct PDO operations are not supported. Use Laravel query builder.');
            }

            /**
             * @param string $statement
             * @return int|false
             */
            public function exec($statement)
            {
                throw new \RuntimeException('Direct PDO operations are not supported. Use Laravel query builder.');
            }

            /**
             * @param string $query
             * @param int|null $fetchMode
             * @param mixed ...$fetchModeArgs
             * @return PDOStatement|false
             */
            public function query($query, $fetchMode = null, ...$fetchModeArgs)
            {
                throw new \RuntimeException('Direct PDO operations are not supported. Use Laravel query builder.');
            }

            /**
             * @return bool
             */
            public function inTransaction(): bool
            {
                // Return the tracked transaction state
                // The RemoteHttpConnection manages transactions via HTTP
                return $this->transactionState;
            }

            /**
             * @return bool
             */
            public function beginTransaction(): bool
            {
                // Track transaction state but don't actually begin a transaction
                // The RemoteHttpConnection handles this via HTTP
                $this->transactionState = true;

                return true;
            }

            /**
             * @return bool
             */
            public function commit(): bool
            {
                // Track transaction state but don't actually commit
                // The RemoteHttpConnection handles this via HTTP
                $this->transactionState = false;

                return true;
            }

            /**
             * @return bool
             */
            public function rollBack(): bool
            {
                // Track transaction state but don't actually rollback
                // The RemoteHttpConnection handles this via HTTP
                $this->transactionState = false;

                return true;
            }
        };
    }
}
