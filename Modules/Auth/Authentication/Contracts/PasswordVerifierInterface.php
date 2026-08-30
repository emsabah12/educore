<?php

declare(strict_types=1);

namespace Modules\Auth\Authentication\Contracts;

/**
 * Canonical password verification boundary.
 *
 * Implementations receive only the submitted plaintext password and a stored
 * password hash. User lookup, dummy-hash selection, auditing, and credential
 * issuance remain responsibilities of the authentication orchestration layer.
 */
interface PasswordVerifierInterface
{
    public function verify(
        string $plainPassword,
        string $passwordHash,
    ): bool;
}
