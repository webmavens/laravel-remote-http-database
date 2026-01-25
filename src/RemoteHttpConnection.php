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

            $response = $this->httpClient->sendQuery($query, $bindings, 'select');

            // Convert results to objects if needed
            $results = $response['results'] ?? [];
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

            $response = $this->httpClient->sendQuery($query, $bindings, 'insert');

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

            $response = $this->httpClient->sendQuery($query, $bindings, 'statement');

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

            $response = $this->httpClient->sendQuery($query, $bindings, 'affecting');

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

            $response = $this->httpClient->sendQuery($query, [], 'unprepared');

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

            $response = $this->httpClient->sendQuery($query, $bindings, 'select');

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
        $this->run('BEGIN', [], function ($query, $bindings) {
            $this->httpClient->sendQuery($query, $bindings, 'transaction');
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
                $this->httpClient->sendQuery($query, $bindings, 'transaction');
            });
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
                $this->httpClient->sendQuery($query, $bindings, 'transaction');
            });
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
            $this->httpClient->sendQuery($query, $bindings, 'transaction');
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
}
