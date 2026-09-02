<?php

declare(strict_types=1);

namespace Modules\Core\Tests\Unit\Person;

use Modules\Core\Person\Services\PersonIdentifierCipher;
use RuntimeException;
use Tests\TestCase;

final class PersonIdentifierCipherTest extends TestCase
{
    public function test_encrypt_then_decrypt_returns_original_value(): void
    {
        $cipher = new PersonIdentifierCipher();

        $original = '3201234567890001';

        $encrypted = $cipher->encrypt($original);
        $decrypted = $cipher->decrypt($encrypted);

        $this->assertSame($original, $decrypted);
    }

    public function test_encrypted_value_never_contains_raw_value_substring(): void
    {
        $cipher = new PersonIdentifierCipher();

        $original = '3201234567890001';

        $encrypted = $cipher->encrypt($original);

        $this->assertStringNotContainsString(
            $original,
            $encrypted,
        );
    }

    public function test_encrypting_same_value_twice_produces_different_ciphertext(): void
    {
        // Laravel Crypt menggunakan random IV per panggilan — ciphertext
        // harus berbeda meski plaintext sama (mencegah pattern leakage).
        $cipher = new PersonIdentifierCipher();

        $original = '3201234567890001';

        $encryptedFirst = $cipher->encrypt($original);
        $encryptedSecond = $cipher->encrypt($original);

        $this->assertNotSame($encryptedFirst, $encryptedSecond);

        $this->assertSame(
            $original,
            $cipher->decrypt($encryptedFirst),
        );
        $this->assertSame(
            $original,
            $cipher->decrypt($encryptedSecond),
        );
    }

    public function test_fingerprint_is_deterministic_for_same_value(): void
    {
        $cipher = new PersonIdentifierCipher();

        $original = '3201234567890001';

        $this->assertSame(
            $cipher->fingerprint($original),
            $cipher->fingerprint($original),
        );
    }

    public function test_fingerprint_differs_for_different_values(): void
    {
        $cipher = new PersonIdentifierCipher();

        $this->assertNotSame(
            $cipher->fingerprint('3201234567890001'),
            $cipher->fingerprint('3201234567890002'),
        );
    }

    public function test_fingerprint_is_sixty_four_character_hex(): void
    {
        $cipher = new PersonIdentifierCipher();

        $fingerprint = $cipher->fingerprint('3201234567890001');

        $this->assertSame(64, strlen($fingerprint));
        $this->assertMatchesRegularExpression(
            '/^[0-9a-f]{64}$/',
            $fingerprint,
        );
    }

    public function test_fingerprint_does_not_reveal_raw_value_as_substring(): void
    {
        $cipher = new PersonIdentifierCipher();

        $original = '3201234567890001';

        $fingerprint = $cipher->fingerprint($original);

        $this->assertStringNotContainsString(
            $original,
            $fingerprint,
        );
    }

    public function test_encrypt_rejects_empty_value(): void
    {
        $cipher = new PersonIdentifierCipher();

        $this->expectException(RuntimeException::class);

        $cipher->encrypt('   ');
    }

    public function test_fingerprint_rejects_empty_value(): void
    {
        $cipher = new PersonIdentifierCipher();

        $this->expectException(RuntimeException::class);

        $cipher->fingerprint('   ');
    }

    public function test_fingerprint_fails_closed_when_key_not_configured(): void
    {
        config(['person-identifier.fingerprint_key' => null]);

        $cipher = new PersonIdentifierCipher();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage(
            'PERSON_IDENTIFIER_FINGERPRINT_KEY is not configured.',
        );

        $cipher->fingerprint('3201234567890001');
    }
}
