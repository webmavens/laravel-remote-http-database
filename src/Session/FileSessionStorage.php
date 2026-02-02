<?php

namespace Laravel\RemoteHttpDatabase\Session;

class FileSessionStorage implements SessionStorageInterface
{
    /**
     * The directory for session files.
     *
     * @var string
     */
    protected $directory;

    /**
     * Session lifetime in seconds.
     *
     * @var int
     */
    protected $lifetime;

    /**
     * Create a new file session storage instance.
     *
     * @param  string|null  $directory
     * @param  int  $lifetime
     */
    public function __construct(?string $directory = null, int $lifetime = 3600)
    {
        $this->directory = $directory ?? sys_get_temp_dir();
        $this->lifetime = $lifetime;
    }

    /**
     * Get the session file path.
     *
     * @param  string  $sessionId
     * @return string
     */
    protected function getSessionPath(string $sessionId): string
    {
        // Sanitize session ID to prevent directory traversal
        $safeSessionId = preg_replace('/[^a-f0-9]/i', '', $sessionId);

        return $this->directory.'/remote_db_session_'.$safeSessionId.'.json';
    }

    /**
     * {@inheritdoc}
     */
    public function get(string $sessionId): array
    {
        $path = $this->getSessionPath($sessionId);

        if (! file_exists($path)) {
            return [];
        }

        $content = file_get_contents($path);
        if ($content === false) {
            return [];
        }

        $data = json_decode($content, true);

        return is_array($data) ? $data : [];
    }

    /**
     * {@inheritdoc}
     */
    public function save(string $sessionId, array $data): void
    {
        $path = $this->getSessionPath($sessionId);

        // Use file locking to prevent race conditions
        $handle = fopen($path, 'c');
        if ($handle === false) {
            return;
        }

        if (flock($handle, LOCK_EX)) {
            ftruncate($handle, 0);
            fwrite($handle, json_encode($data));
            fflush($handle);
            flock($handle, LOCK_UN);
        }

        fclose($handle);
    }

    /**
     * {@inheritdoc}
     */
    public function delete(string $sessionId): void
    {
        $path = $this->getSessionPath($sessionId);

        if (file_exists($path)) {
            @unlink($path);
        }
    }

    /**
     * {@inheritdoc}
     */
    public function cleanup(): void
    {
        // Only cleanup occasionally (1% chance per request) to reduce I/O
        if (random_int(1, 100) > 1) {
            return;
        }

        $pattern = $this->directory.'/remote_db_session_*.json';
        $files = glob($pattern);

        if ($files === false) {
            return;
        }

        $expirationTime = time() - $this->lifetime;

        foreach ($files as $file) {
            if (filemtime($file) < $expirationTime) {
                @unlink($file);
            }
        }
    }
}
