<?php

declare(strict_types=1);

namespace Modules\Auth\BrowserSession\Contracts;

/**
 * Least-privilege BrowserSession capability for user-only authentication.
 *
 * Callers may request one server-held canonical bearer credential to prove the
 * BrowserSession owner through the existing canonical identity middleware, but
 * cannot enumerate Membership ids or inspect the complete credential map.
 */
interface BrowserSessionAuthenticationCredentialProviderInterface
{
    /**
     * Return one canonical bearer credential suitable for user identity
     * authentication, or null when the BrowserSession has no usable credential.
     */
    public function credentialForAuthentication(): ?string;
}
