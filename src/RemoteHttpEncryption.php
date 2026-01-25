<?php

namespace Laravel\RemoteHttpDatabase;

use RuntimeException;

class RemoteHttpEncryption
{
    /**
     * The encryption key.
     *
     * @var string
     */
    protected $key;

    /**
     * The cipher method.
     *
     * @var string
     */
    protected $cipher = 'aes-256-gcm';

    /**
     * Create a new encryption instance.
     *
     * @param  string  $key
     * @return void
     */
    public function __construct($key)
    {
        if (strlen($key) !== 32) {
            throw new RuntimeException('Encryption key must be exactly 32 bytes for AES-256.');
        }

        $this->key = $key;
    }

    /**
     * Encrypt the given value.
     *
     * @param  mixed  $value
     * @return string
     *
     * @throws \RuntimeException
     */
    public function encrypt($value)
    {
        $iv = random_bytes(12); // 96-bit IV for GCM
        $tag = '';

        $encrypted = openssl_encrypt(
            json_encode($value),
            $this->cipher,
            $this->key,
            OPENSSL_RAW_DATA,
            $iv,
            $tag
        );

        if ($encrypted === false) {
            throw new RuntimeException('Encryption failed.');
        }

        // Return base64 encoded: IV + Tag + Encrypted data
        return base64_encode($iv.$tag.$encrypted);
    }

    /**
     * Decrypt the given value.
     *
     * @param  string  $payload
     * @return mixed
     *
     * @throws \RuntimeException
     */
    public function decrypt($payload)
    {
        $data = base64_decode($payload, true);

        if ($data === false) {
            throw new RuntimeException('Invalid encrypted payload.');
        }

        // Extract IV (12 bytes), Tag (16 bytes), and encrypted data
        $iv = substr($data, 0, 12);
        $tag = substr($data, 12, 16);
        $encrypted = substr($data, 28);

        $decrypted = openssl_decrypt(
            $encrypted,
            $this->cipher,
            $this->key,
            OPENSSL_RAW_DATA,
            $iv,
            $tag
        );

        if ($decrypted === false) {
            throw new RuntimeException('Decryption failed.');
        }

        $decoded = json_decode($decrypted, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new RuntimeException('Failed to decode decrypted data: '.json_last_error_msg());
        }

        return $decoded;
    }
}
