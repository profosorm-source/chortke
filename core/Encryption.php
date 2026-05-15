<?php

declare(strict_types=1);

namespace Core;

/**
 * Encryption - Utility for symmetric encryption using AES-256-CBC
 */
class Encryption
{
    /**
     * Encrypt a string
     */
    public function encrypt(string $value): string
    {
        $key = (string)config('app.key');
        if (empty($key)) {
            throw new \RuntimeException('Encryption key not found in configuration.');
        }

        $iv = substr($key, 0, 16);
        $encrypted = openssl_encrypt($value, 'aes-256-cbc', $key, 0, $iv);
        
        if ($encrypted === false) {
            throw new \RuntimeException('Encryption failed.');
        }

        return $encrypted;
    }

    /**
     * Decrypt a string
     */
    public function decrypt(string $value): string
    {
        $key = (string)config('app.key');
        if (empty($key)) {
            throw new \RuntimeException('Encryption key not found in configuration.');
        }

        $iv = substr($key, 0, 16);
        $decrypted = openssl_decrypt($value, 'aes-256-cbc', $key, 0, $iv);

        if ($decrypted === false) {
            return $value; // Return original if decryption fails (fallback for non-encrypted data)
        }

        return $decrypted;
    }

    /**
     * Redact sensitive information (e.g., national code)
     */
    public function redact(string $value, int $keepLength = 4): string
    {
        if (strlen($value) <= $keepLength) {
            return str_repeat('*', strlen($value));
        }
        return str_repeat('*', strlen($value) - $keepLength) . substr($value, -$keepLength);
    }
}
