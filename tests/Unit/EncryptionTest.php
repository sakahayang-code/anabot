<?php
declare(strict_types=1);

namespace AnaBot\Tests\Unit;

use AnaBot\Core\Encryption;
use RuntimeException;

class EncryptionTest
{
    public function run(): void
    {
        echo "Testing Encryption Service..." . PHP_EOL;

        $key = Encryption::generateKey();
        $encryption = new Encryption($key);

        // Test 1: Basic Encrypt & Decrypt
        $plain = "SecretToken12345!@#";
        $encrypted = $encryption->encrypt($plain);
        assert($encrypted !== $plain, 'Encrypted string must differ from plain');
        $decrypted = $encryption->decrypt($encrypted);
        assert($decrypted === $plain, 'Decrypted text must match original');
        echo "  [PASS] Basic AES-256-GCM encryption & decryption" . PHP_EOL;

        // Test 2: Tamper Resistance (GCM auth tag check)
        $tampered = substr($encrypted, 0, -4) . 'AAAA';
        $tamperCaught = false;
        try {
            $encryption->decrypt($tampered);
        } catch (RuntimeException) {
            $tamperCaught = true;
        }
        assert($tamperCaught, 'Tampered ciphertext must fail decryption');
        echo "  [PASS] Integrity verification (Tamper detection)" . PHP_EOL;

        // Test 3: JSON Encryption
        $data = ['token' => 'abc', 'expires_in' => 3600, 'scopes' => ['read', 'write']];
        $jsonEncrypted = $encryption->encryptJson($data);
        $jsonDecrypted = $encryption->decryptJson($jsonEncrypted);
        assert($jsonDecrypted === $data, 'Decrypted JSON array must match original');
        echo "  [PASS] JSON encryption & decryption" . PHP_EOL;
    }
}
