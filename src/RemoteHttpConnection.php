<?php

namespace Laravel\RemoteHttpDatabase;

use Closure;
use Illuminate\Database\Connection;
use Illuminate\Database\Query\Grammars\MySqlGrammar as QueryGrammar;
use Illuminate\Database\Query\Processors\MySqlProcessor;
use Illuminate\Database\Schema\Grammars\MySqlGrammar as SchemaGrammar;
use Illuminate\Database\Schema\MySqlBuilder;
use PDO;

class RemoteHttpConnection extends Connection
{
    /**
     * The HTTP client instance.
     *
     * @var \Laravel\RemoteHttpDatabase\RemoteHttpClient
     */
    protected $httpClient;

    /**
     * The last inserted ID.
     *
     * @var string|int|null
     */
    protected $lastInsertId;

    /**
     * The detected remote database type (mysql, sqlite, etc.).
     *
     * @var string|null
     */
    protected $remoteDatabaseType = null;

    /**
     * Query batch queue for batching multiple queries.
     *
     * @var array
     */
    protected $queryBatch = [];

    /**
     * Whether batching is enabled.
     *
     * @var bool
     */
    protected $batchingEnabled = false;

    /**
     * Query result cache.
     *
     * @var array
     */
    protected static $queryCache = [];

    /**
     * Whether caching is enabled.
     *
     * @var bool
     */
    protected $cachingEnabled = false;

    /**
     * Cache TTL in seconds.
     *
     * @var int
     */
    protected $cacheTtl = 60;

    /**
     * Maximum number of cached queries.
     *
     * @var int
     */
    protected $cacheMaxSize = 1000;

    /**
     * Create a new remote HTTP connection instance.
     *
     * @param  \PDO|\Closure  $pdo
     * @param  string  $database
     * @param  string  $tablePrefix
     * @param  array  $config
     * @return void
     */
    public function __construct($pdo, $database = '', $tablePrefix = '', array $config = [])
    {
        parent::__construct($pdo, $database, $tablePrefix, $config);

        // Initialize HTTP client
        $encryption = new RemoteHttpEncryption($config['encryption_key']);
        $this->httpClient = new RemoteHttpClient(
            $config['endpoint'],
            $config['api_key'],
            $encryption,
            [
                'timeout' => $config['timeout'] ?? 30,
                'verify_ssl' => $config['verify_ssl'] ?? true,
                'retry_attempts' => $config['retry_attempts'] ?? 3,
            ]
        );

        // Enable batching if configured
        $this->batchingEnabled = $config['enable_batching'] ?? env('DB_REMOTE_ENABLE_BATCHING', true);
        
        // Enable caching if configured
        $this->cachingEnabled = $config['enable_caching'] ?? env('DB_REMOTE_ENABLE_CACHING', true);
        $this->cacheTtl = $config['cache_ttl'] ?? env('DB_REMOTE_CACHE_TTL', 60);
    }

    /**
     * Get the driver name.
     *
     * @return string
     */
    public function getDriverName()
    {
        return 'remote-http';
    }

    /**
     * Get a human-readable name for the connection driver.
     *
     * @return string
     */
    public function getDriverTitle()
    {
        return 'Remote HTTP MySQL';
    }

    /**
     * Get the default query grammar instance.
     *
     * @return \Illuminate\Database\Query\Grammars\MySqlGrammar
     */
    protected function getDefaultQueryGrammar()
    {
        return new QueryGrammar($this);
    }

    /**
     * Get the default schema grammar instance.
     *
     * @return \Illuminate\Database\Schema\Grammars\MySqlGrammar
     */
    protected function getDefaultSchemaGrammar()
    {
        return new SchemaGrammar($this);
    }

    /**
     * Get the default post processor instance.
     *
     * @return \Illuminate\Database\Query\Processors\MySqlProcessor
     */
    protected function getDefaultPostProcessor()
    {
        return new MySqlProcessor;
    }

    /**
     * Get a schema builder instance for the connection.
     *
     * @return \Illuminate\Database\Schema\MySqlBuilder
     */
    public function getSchemaBuilder()
    {
        if (is_null($this->schemaGrammar)) {
            $this->useDefaultSchemaGrammar();
        }

        return new MySqlBuilder($this);
    }

    /**
     * Run a select statement against the database.
     *
     * @param  string  $query
     * @param  array  $bindings
     * @param  bool  $useReadPdo
     * @return array
     */
    public function select($query, $bindings = [], $useReadPdo = true)
    {
        return $this->run($query, $bindings, function ($query, $bindings) {
            if ($this->pretending()) {
                return [];
            }

            // Process query to handle database-specific syntax
            $query = $this->processQueryForRemoteDatabase($query);

            // Check cache first (only for SELECT queries)
            if ($this->cachingEnabled) {
                $cacheKey = $this->getCacheKey($query, $bindings);
                if (isset(self::$queryCache[$cacheKey])) {
                    $cached = self::$queryCache[$cacheKey];
                    if ($cached['expires_at'] > time()) {
                        $results = $cached['results'];
                        // Convert to objects if needed
                        $fetchMode = $this->fetchMode;
                        if ($fetchMode === PDO::FETCH_OBJ) {
                            return array_map(function ($row) {
                                return (object) $row;
                            }, $results);
                        }
                        return $results;
                    } else {
                        unset(self::$queryCache[$cacheKey]);
                    }
                }
            }

            $response = $this->httpClient->sendQuery($this->getName(), $query, $bindings, 'select');

            // Convert results to objects if needed
            $results = $response['results'] ?? [];
            
            // Cache the results
            if ($this->cachingEnabled) {
                $cacheKey = $this->getCacheKey($query, $bindings);
                self::$queryCache[$cacheKey] = [
                    'results' => $results,
                    'expires_at' => time() + $this->cacheTtl,
                ];
                
                // Limit cache size to prevent memory issues
                if (count(self::$queryCache) > 1000) {
                    $this->cleanExpiredCache();
                }
            }
            
            $fetchMode = $this->fetchMode;

            if ($fetchMode === PDO::FETCH_OBJ) {
                return array_map(function ($row) {
                    return (object) $row;
                }, $results);
            }

            return $results;
        });
    }

    /**
     * Run a select statement and return a generator.
     *
     * @param  string  $query
     * @param  array  $bindings
     * @param  bool  $useReadPdo
     * @return \Generator
     */
    public function cursor($query, $bindings = [], $useReadPdo = true)
    {
        $results = $this->select($query, $bindings, $useReadPdo);

        foreach ($results as $record) {
            yield $record;
        }
    }

    /**
     * Run an insert statement against the database.
     *
     * @param  string  $query
     * @param  array  $bindings
     * @param  string|null  $sequence
     * @return bool
     */
    public function insert($query, $bindings = [], $sequence = null)
    {
        return $this->run($query, $bindings, function ($query, $bindings) use ($sequence) {
            if ($this->pretending()) {
                return true;
            }

            // Invalidate cache on write operations
            $this->invalidateCache();

            $response = $this->httpClient->sendQuery($this->getName(), $query, $bindings, 'insert');

            $this->recordsHaveBeenModified();

            // Store last insert ID if provided
            if (isset($response['last_insert_id'])) {
                $this->lastInsertId = $response['last_insert_id'];
            }

            return true;
        });
    }

    /**
     * Execute an SQL statement and return the boolean result.
     *
     * @param  string  $query
     * @param  array  $bindings
     * @return bool
     */
    public function statement($query, $bindings = [])
    {
        return $this->run($query, $bindings, function ($query, $bindings) {
            if ($this->pretending()) {
                return true;
            }

            // Invalidate cache on write operations
            $this->invalidateCache();

            $response = $this->httpClient->sendQuery($this->getName(), $query, $bindings, 'statement');

            $this->recordsHaveBeenModified();

            return isset($response['success']) ? $response['success'] : true;
        });
    }

    /**
     * Run an SQL statement and get the number of rows affected.
     *
     * @param  string  $query
     * @param  array  $bindings
     * @return int
     */
    public function affectingStatement($query, $bindings = [])
    {
        return $this->run($query, $bindings, function ($query, $bindings) {
            if ($this->pretending()) {
                return 0;
            }

            // Invalidate cache on write operations
            $this->invalidateCache();

            $response = $this->httpClient->sendQuery($this->getName(), $query, $bindings, 'affecting');

            $rowsAffected = $response['rows_affected'] ?? 0;

            $this->recordsHaveBeenModified($rowsAffected > 0);

            return (int) $rowsAffected;
        });
    }

    /**
     * Run a raw, unprepared query against the database.
     *
     * @param  string  $query
     * @return bool
     */
    public function unprepared($query)
    {
        return $this->run($query, [], function ($query) {
            if ($this->pretending()) {
                return true;
            }

            // Invalidate cache on write operations
            $this->invalidateCache();

            $response = $this->httpClient->sendQuery($this->getName(), $query, [], 'unprepared');

            $this->recordsHaveBeenModified();

            return isset($response['success']) ? $response['success'] : true;
        });
    }

    /**
     * Run a select statement against the database and returns all of the result sets.
     *
     * @param  string  $query
     * @param  array  $bindings
     * @param  bool  $useReadPdo
     * @return array
     */
    public function selectResultSets($query, $bindings = [], $useReadPdo = true)
    {
        return $this->run($query, $bindings, function ($query, $bindings) {
            if ($this->pretending()) {
                return [];
            }

            $response = $this->httpClient->sendQuery($this->getName(), $query, $bindings, 'select');

            // For multiple result sets, return as array of arrays
            return [$response['results'] ?? []];
        });
    }

    /**
     * Get the last insert ID.
     *
     * @param  string|null  $sequence
     * @return string|int|null
     */
    public function getLastInsertId($sequence = null)
    {
        return $this->lastInsertId;
    }

    /**
     * Execute the statement to start a new transaction.
     *
     * @return void
     */
    protected function executeBeginTransactionStatement()
    {
        // Update mock PDO transaction state
        $pdo = $this->getPdo();
        if (method_exists($pdo, 'beginTransaction')) {
            $pdo->beginTransaction();
        }

        $this->run('BEGIN', [], function ($query, $bindings) {
            $this->httpClient->sendQuery($this->getName(), $query, $bindings, 'transaction');
        });
    }

    /**
     * Commit the active database transaction.
     *
     * @return void
     */
    public function commit()
    {
        if ($this->transactionLevel() == 1) {
            $this->run('COMMIT', [], function ($query, $bindings) {
                $this->httpClient->sendQuery($this->getName(), $query, $bindings, 'transaction');
            });

            // Update mock PDO transaction state
            $pdo = $this->getPdo();
            if (method_exists($pdo, 'commit')) {
                $pdo->commit();
            }
        }

        parent::commit();
    }

    /**
     * Rollback the active database transaction.
     *
     * @param  int|null  $toLevel
     * @return void
     */
    public function rollBack($toLevel = null)
    {
        if ($this->transactionLevel() == 1) {
            $this->run('ROLLBACK', [], function ($query, $bindings) {
                $this->httpClient->sendQuery($this->getName(), $query, $bindings, 'transaction');
            });

            // Update mock PDO transaction state
            $pdo = $this->getPdo();
            if (method_exists($pdo, 'rollBack')) {
                $pdo->rollBack();
            }
        }

        parent::rollBack($toLevel);
    }

    /**
     * Create a save point within the database.
     *
     * @return void
     */
    protected function createSavepoint()
    {
        $savepoint = 'trans'.($this->transactions + 1);
        $this->run($this->queryGrammar->compileSavepoint($savepoint), [], function ($query, $bindings) {
            $this->httpClient->sendQuery($this->getName(), $query, $bindings, 'transaction');
        });
    }

    /**
     * Get the server version for the connection.
     *
     * @return string
     */
    public function getServerVersion(): string
    {
        // Query the remote server for version
        try {
            $result = $this->selectOne('SELECT VERSION() as version');
            return $result->version ?? 'Unknown';
        } catch (\Exception $e) {
            return 'Unknown';
        }
    }

    /**
     * Disconnect from the underlying database connection.
     *
     * @return void
     */
    public function disconnect()
    {
        $this->httpClient->clearSession();
        parent::disconnect();
    }

    /**
     * Detect the remote database type.
     *
     * @return string
     */
    protected function detectRemoteDatabaseType(): string
    {
        if ($this->remoteDatabaseType !== null) {
            return $this->remoteDatabaseType;
        }

        // Mark as detecting to avoid recursion
        $this->remoteDatabaseType = 'detecting';

        try {
            // Try to detect SQLite first (SQLite doesn't support VERSION())
            try {
                $response = $this->httpClient->sendQuery($this->getName(), 'SELECT sqlite_version() as version', [], 'select');
                if (isset($response['results']) && ! empty($response['results'])) {
                    $this->remoteDatabaseType = 'sqlite';
                    return 'sqlite';
                }
            } catch (\Exception $e) {
                // Not SQLite, try MySQL/MariaDB
            }

            // Try MySQL/MariaDB VERSION()
            try {
                $response = $this->httpClient->sendQuery($this->getName(), 'SELECT VERSION() as version', [], 'select');
                if (isset($response['results']) && ! empty($response['results'])) {
                    $version = $response['results'][0]['version'] ?? '';
                    if (stripos($version, 'mariadb') !== false) {
                        $this->remoteDatabaseType = 'mariadb';
                    } else {
                        $this->remoteDatabaseType = 'mysql';
                    }
                    return $this->remoteDatabaseType;
                }
            } catch (\Exception $e) {
                // Fallback to mysql if detection fails
            }

            // Default to mysql if all detection fails
            $this->remoteDatabaseType = 'mysql';
            return 'mysql';
        } catch (\Exception $e) {
            // Default to mysql if all detection fails
            $this->remoteDatabaseType = 'mysql';
            return 'mysql';
        }
    }

    /**
     * Process query to handle database-specific syntax differences.
     *
     * @param  string  $query
     * @return string
     */
    protected function processQueryForRemoteDatabase(string $query): string
    {
        // Skip processing if we're still detecting (to avoid recursion)
        if ($this->remoteDatabaseType === 'detecting') {
            return $query;
        }

        $dbType = $this->detectRemoteDatabaseType();

        // SQLite doesn't support FOR UPDATE, so we need to strip it
        if ($dbType === 'sqlite') {
            // Remove FOR UPDATE clause (case-insensitive)
            // This handles: "FOR UPDATE", "FOR UPDATE NOWAIT", "FOR UPDATE SKIP LOCKED", etc.
            $query = preg_replace('/\s+FOR\s+UPDATE(\s+(NOWAIT|SKIP\s+LOCKED|OF\s+[^\s]+))?(\s+.*)?$/i', '', $query);
        }

        return $query;
    }

    /**
     * Get cache key for a query.
     *
     * @param  string  $query
     * @param  array  $bindings
     * @return string
     */
    protected function getCacheKey($query, $bindings)
    {
        return md5($query.serialize($bindings));
    }

    /**
     * Invalidate all cached queries.
     *
     * @return void
     */
    protected function invalidateCache()
    {
        self::$queryCache = [];
    }

    /**
     * Clean expired cache entries.
     *
     * @return void
     */
    protected function cleanExpiredCache()
    {
        $now = time();
        foreach (self::$queryCache as $key => $cached) {
            if ($cached['expires_at'] <= $now) {
                unset(self::$queryCache[$key]);
            }
        }
    }

    /**
     * Execute batched queries.
     *
     * @return array
     */
    protected function executeBatch()
    {
        if (empty($this->queryBatch)) {
            return [];
        }

        $results = $this->httpClient->sendBatch($this->getName(), $this->queryBatch);
        $this->queryBatch = [];

        return $results;
    }

    /**
     * Flush any pending batched queries.
     *
     * @return void
     */
    public function flushBatch()
    {
        if (! empty($this->queryBatch)) {
            $this->executeBatch();
        }
    }
}
