<?php

namespace Laravel\RemoteHttpDatabase\Session;

use Illuminate\Support\Facades\DB;

class DatabaseSessionStorage implements SessionStorageInterface
{
    /**
     * The table name for session storage.
     *
     * @var string
     */
    protected $table;

    /**
     * Session lifetime in seconds.
     *
     * @var int
     */
    protected $lifetime;

    /**
     * The database connection to use.
     *
     * @var string|null
     */
    protected $connection;

    /**
     * Create a new database session storage instance.
     *
     * @param  string  $table
     * @param  int  $lifetime
     * @param  string|null  $connection
     */
    public function __construct(string $table = 'remote_db_sessions', int $lifetime = 3600, ?string $connection = null)
    {
        $this->table = $table;
        $this->lifetime = $lifetime;
        $this->connection = $connection;
    }

    /**
     * Get the database query builder for the session table.
     *
     * @return \Illuminate\Database\Query\Builder
     */
    protected function table()
    {
        return DB::connection($this->connection)->table($this->table);
    }

    /**
     * {@inheritdoc}
     */
    public function get(string $sessionId): array
    {
        $record = $this->table()->where('id', $sessionId)->first();

        if ($record === null) {
            return [];
        }

        $data = json_decode($record->data, true);

        return is_array($data) ? $data : [];
    }

    /**
     * {@inheritdoc}
     */
    public function save(string $sessionId, array $data): void
    {
        $now = now();
        $exists = $this->table()->where('id', $sessionId)->exists();

        if ($exists) {
            $this->table()->where('id', $sessionId)->update([
                'data' => json_encode($data),
                'updated_at' => $now,
            ]);
        } else {
            $this->table()->insert([
                'id' => $sessionId,
                'data' => json_encode($data),
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }

    /**
     * {@inheritdoc}
     */
    public function delete(string $sessionId): void
    {
        $this->table()->where('id', $sessionId)->delete();
    }

    /**
     * {@inheritdoc}
     */
    public function cleanup(): void
    {
        // Only cleanup occasionally (1% chance per request) to reduce load
        if (random_int(1, 100) > 1) {
            return;
        }

        $expirationTime = now()->subSeconds($this->lifetime);

        $this->table()->where('updated_at', '<', $expirationTime)->delete();
    }
}
