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
    public function encrypt(string $value, array $options = []): string
    {
        $key = secure_key();
        $version = $options['version'] ?? 2;

        if ($version === 2) {
            // Generate a random 12-byte IV for GCM (standard)
            $iv = random_bytes(12);
            $tag = '';
            
            // Derive version-specific sub-key to support key isolation/rotation
            $vKey = hash_hmac('sha256', 'encryption_v2_key', $key, true);

            $encrypted = openssl_encrypt($value, 'aes-256-gcm', $vKey, OPENSSL_RAW_DATA, $iv, $tag);
            if ($encrypted === false) {
                throw new \RuntimeException('Encryption failed.');
            }

            // Store GCM parts as: v2:<iv>:<tag>:<encrypted_data_base64>
            $payload = base64_encode($iv) . ':' . base64_encode($tag) . ':' . base64_encode($encrypted);
            return 'v2:' . base64_encode($payload);
        }

        // Fallback to legacy v1 (AES-256-CBC)
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
        if (strpos($value, 'v2:') === 0) {
            try {
                $key = secure_key();
                $vKey = hash_hmac('sha256', 'encryption_v2_key', $key, true);
                
                $payload = base64_decode(substr($value, 3));
                if ($payload === false) {
                    return $value;
                }
                
                $parts = explode(':', $payload, 3);
                if (count($parts) !== 3) {
                    return $value;
                }
                
                $iv = base64_decode($parts[0]);
                $tag = base64_decode($parts[1]);
                $encrypted = base64_decode($parts[2]);
                
                if ($iv === false || $tag === false || $encrypted === false) {
                    return $value;
                }
                
                $decrypted = openssl_decrypt($encrypted, 'aes-256-gcm', $vKey, OPENSSL_RAW_DATA, $iv, $tag);
                if ($decrypted === false) {
                    return $value;
                }
                
                return $decrypted;
            } catch (\Throwable $e) {
                return $value;
            }
        }

        $key = secure_key();
        $iv = substr($key, 0, 16);

        // Strip v1: if present
        $cleanValue = $value;
        if (strpos($value, 'v1:') === 0) {
            $cleanValue = substr($value, 3);
        }

        $decrypted = openssl_decrypt($cleanValue, 'aes-256-cbc', $key, 0, $iv);

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
