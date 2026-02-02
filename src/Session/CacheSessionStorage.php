<?php

namespace Laravel\RemoteHttpDatabase\Session;

use Illuminate\Support\Facades\Cache;

class CacheSessionStorage implements SessionStorageInterface
{
    /**
     * Session lifetime in seconds.
     *
     * @var int
     */
    protected $lifetime;

    /**
     * The cache store to use.
     *
     * @var string|null
     */
    protected $store;

    /**
     * The key prefix for session data.
     *
     * @var string
     */
    protected $prefix = 'remote_db_session:';

    /**
     * Create a new cache session storage instance.
     *
     * @param  int  $lifetime
     * @param  string|null  $store
     */
    public function __construct(int $lifetime = 3600, ?string $store = null)
    {
        $this->lifetime = $lifetime;
        $this->store = $store;
    }

    /**
     * Get the cache key for a session.
     *
     * @param  string  $sessionId
     * @return string
     */
    protected function getKey(string $sessionId): string
    {
        return $this->prefix.$sessionId;
    }

    /**
     * Get the cache instance.
     *
     * @return \Illuminate\Contracts\Cache\Repository
     */
    protected function cache()
    {
        return $this->store ? Cache::store($this->store) : Cache::store();
    }

    /**
     * {@inheritdoc}
     */
    public function get(string $sessionId): array
    {
        $data = $this->cache()->get($this->getKey($sessionId));

        if ($data === null) {
            return [];
        }

        return is_array($data) ? $data : [];
    }

    /**
     * {@inheritdoc}
     */
    public function save(string $sessionId, array $data): void
    {
        $this->cache()->put($this->getKey($sessionId), $data, $this->lifetime);
    }

    /**
     * {@inheritdoc}
     */
    public function delete(string $sessionId): void
    {
        $this->cache()->forget($this->getKey($sessionId));
    }

    /**
     * {@inheritdoc}
     */
    public function cleanup(): void
    {
        // Cache handles expiration automatically via TTL, no cleanup needed
    }
}
