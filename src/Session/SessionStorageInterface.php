<?php

namespace Laravel\RemoteHttpDatabase\Session;

interface SessionStorageInterface
{
    /**
     * Get session data by ID.
     *
     * @param  string  $sessionId
     * @return array
     */
    public function get(string $sessionId): array;

    /**
     * Save session data.
     *
     * @param  string  $sessionId
     * @param  array  $data
     * @return void
     */
    public function save(string $sessionId, array $data): void;

    /**
     * Delete a session.
     *
     * @param  string  $sessionId
     * @return void
     */
    public function delete(string $sessionId): void;

    /**
     * Clean up expired sessions.
     *
     * @return void
     */
    public function cleanup(): void;
}
