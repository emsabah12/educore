<?php

declare(strict_types=1);

namespace Modules\Auth\Authentication\Services;

use Illuminate\Support\Facades\Hash;
use Modules\Auth\Authentication\Contracts\PasswordVerifierInterface;
use Throwable;

final class HashPasswordVerifier implements PasswordVerifierInterface
{
    public function verify(
        string $plainPassword,
        string $passwordHash,
    ): bool {
        /*
         * Empty or malformed persisted hashes must fail closed.
         *
         * Plaintext passwords are intentionally not normalized or trimmed:
         * whitespace may legitimately be part of a password.
         */
        if ($passwordHash === '') {
            return false;
        }

        try {
            return Hash::check(
                $plainPassword,
                $passwordHash,
            );
        } catch (Throwable $exception) {
            /*
             * Hash-driver failures or malformed stored hashes must never turn
             * into successful authentication.
             *
             * Do not log the password or stored hash.
             */
            report($exception);

            return false;
        }
    }
}
