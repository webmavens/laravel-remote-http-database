<?php

namespace Laravel\RemoteHttpDatabase\Session;

use InvalidArgumentException;

class SessionStorageFactory
{
    /**
     * Create a session storage instance based on configuration.
     *
     * @param  string  $driver
     * @param  array  $config
     * @return \Laravel\RemoteHttpDatabase\Session\SessionStorageInterface
     *
     * @throws \InvalidArgumentException
     */
    public static function create(string $driver, array $config = []): SessionStorageInterface
    {
        $lifetime = $config['lifetime'] ?? 3600;

        switch ($driver) {
            case 'file':
                return new FileSessionStorage(
                    $config['directory'] ?? null,
                    $lifetime
                );

            case 'redis':
                return new RedisSessionStorage(
                    $config['connection'] ?? 'default',
                    $lifetime
                );

            case 'database':
                return new DatabaseSessionStorage(
                    $config['table'] ?? 'remote_db_sessions',
                    $lifetime,
                    $config['connection'] ?? null
                );

            case 'cache':
                return new CacheSessionStorage(
                    $lifetime,
                    $config['store'] ?? null
                );

            default:
                throw new InvalidArgumentException("Unsupported session driver: {$driver}");
        }
    }
}
