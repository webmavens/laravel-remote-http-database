<?php

namespace Laravel\RemoteHttpDatabase\Session;

use Illuminate\Support\Facades\Redis;

class RedisSessionStorage implements SessionStorageInterface
{
    /**
     * The Redis connection name.
     *
     * @var string
     */
    protected $connection;

    /**
     * Session lifetime in seconds.
     *
     * @var int
     */
    protected $lifetime;

    /**
     * The key prefix for session data.
     *
     * @var string
     */
    protected $prefix = 'remote_db_session:';

    /**
     * Create a new Redis session storage instance.
     *
     * @param  string  $connection
     * @param  int  $lifetime
     */
    public function __construct(string $connection = 'default', int $lifetime = 3600)
    {
        $this->connection = $connection;
        $this->lifetime = $lifetime;
    }

    /**
     * Get the Redis key for a session.
     *
     * @param  string  $sessionId
     * @return string
     */
    protected function getKey(string $sessionId): string
    {
        return $this->prefix.$sessionId;
    }

    /**
     * {@inheritdoc}
     */
    public function get(string $sessionId): array
    {
        $data = Redis::connection($this->connection)->get($this->getKey($sessionId));

        if ($data === null) {
            return [];
        }

        $decoded = json_decode($data, true);

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * {@inheritdoc}
     */
    public function save(string $sessionId, array $data): void
    {
        Redis::connection($this->connection)->setex(
            $this->getKey($sessionId),
            $this->lifetime,
            json_encode($data)
        );
    }

    /**
     * {@inheritdoc}
     */
    public function delete(string $sessionId): void
    {
        Redis::connection($this->connection)->del($this->getKey($sessionId));
    }

    /**
     * {@inheritdoc}
     */
    public function cleanup(): void
    {
        // Redis handles expiration automatically via TTL, no cleanup needed
    }
}
