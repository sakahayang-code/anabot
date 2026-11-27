<?php
declare(strict_types=1);

namespace AnaBot\Core;

use RuntimeException;

/**
 * AnaBot AES-256-GCM Two-Way Encryption Service
 * 
 * Used for encrypting external API credentials, OAuth tokens, and secret parameters.
 */
class Encryption
{
    private const CIPHER = 'aes-256-gcm';
    private const IV_LENGTH = 12; // 96-bit standard for GCM
    private const TAG_LENGTH = 16; // 128-bit authentication tag

    private string $key;

    public function __construct(?string $key = null)
    {
        $resolvedKey = $key ?? (string) config('security.app_key', '');

        // If key not provided directly in config, check external key path
        if (empty($resolvedKey)) {
            $keyPath = (string) config('security.key_path', '');
            if (!empty($keyPath) && file_exists($keyPath)) {
                $resolvedKey = trim((string) file_get_contents($keyPath));
            }
        }

        if (empty($resolvedKey)) {
            // In testing or bootstrap, check fallback env or error
            $resolvedKey = (string) env('APP_KEY', '');
        }

        if (empty($resolvedKey)) {
            throw new RuntimeException('Encryption key (APP_KEY) is not configured.');
        }

        // Support hex-encoded 64-char key or raw 32-byte key
        if (strlen($resolvedKey) === 64 && ctype_xdigit($resolvedKey)) {
            $this->key = hex2bin($resolvedKey);
        } elseif (strlen($resolvedKey) === 32) {
            $this->key = $resolvedKey;
        } else {
            // Hash key with SHA-256 to guarantee 32 bytes
            $this->key = hash('sha256', $resolvedKey, true);
        }
    }

    public static function generateKey(): string
    {
        return bin2hex(random_bytes(32));
    }

    public function encrypt(string $plainText): string
    {
        $iv = random_bytes(self::IV_LENGTH);
        $tag = '';

        $cipherText = openssl_encrypt(
            $plainText,
            self::CIPHER,
            $this->key,
            OPENSSL_RAW_DATA,
            $iv,
            $tag,
            '',
            self::TAG_LENGTH
        );

        if ($cipherText === false) {
            throw new RuntimeException('Failed to encrypt data using OpenSSL AES-256-GCM.');
        }

        // Pack: IV (12 bytes) + TAG (16 bytes) + CIPHERTEXT
        return base64_encode($iv . $tag . $cipherText);
    }

    public function decrypt(string $payload): string
    {
        $decoded = base64_decode($payload, true);
        if ($decoded === false) {
            throw new RuntimeException('Invalid base64 payload during decryption.');
        }

        $minLen = self::IV_LENGTH + self::TAG_LENGTH;
        if (strlen($decoded) < $minLen) {
            throw new RuntimeException('Encrypted payload is too short or malformed.');
        }

        $iv = substr($decoded, 0, self::IV_LENGTH);
        $tag = substr($decoded, self::IV_LENGTH, self::TAG_LENGTH);
        $cipherText = substr($decoded, $minLen);

        $plainText = openssl_decrypt(
            $cipherText,
            self::CIPHER,
            $this->key,
            OPENSSL_RAW_DATA,
            $iv,
            $tag
        );

        if ($plainText === false) {
            throw new RuntimeException('Decryption failed. Authentication tag verification failed or key is mismatched.');
        }

        return $plainText;
    }

    public function encryptJson(mixed $data): string
    {
        return $this->encrypt(json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
    }

    public function decryptJson(string $payload): mixed
    {
        $json = $this->decrypt($payload);
        return json_decode($json, true, 512, JSON_THROW_ON_ERROR);
    }
}
