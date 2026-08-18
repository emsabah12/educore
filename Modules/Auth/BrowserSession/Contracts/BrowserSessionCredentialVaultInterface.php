<?php

declare(strict_types=1);

namespace Modules\Auth\BrowserSession\Contracts;

/**
 * Server-side custody boundary for canonical membership-scoped bearer credentials.
 *
 * The browser session identifies the authenticated browser user, while each
 * membership credential remains selectable independently by a tab-local
 * membership locator. Implementations must never expose the complete credential
 * map to browser-facing callers.
 */
interface BrowserSessionCredentialVaultInterface
{
    /**
     * Establish credential-vault ownership for the authenticated browser user.
     *
     * Re-establishing the same user preserves already-cached membership
     * credentials. Establishing a different user must discard previous state.
     */
    public function establishForUser(string $userId): void;

    /**
     * Return the browser-session user that currently owns the vault.
     */
    public function userId(): ?string;

    /**
     * Store one canonical bearer credential for one Membership context.
     */
    public function storeMembershipCredential(
        string $membershipId,
        string $bearerCredential,
    ): void;

    /**
     * Resolve the canonical bearer credential for one Membership context.
     */
    public function credentialForMembership(
        string $membershipId,
    ): ?string;

    /**
     * Remove only the credential associated with one Membership context.
     */
    public function forgetMembershipCredential(
        string $membershipId,
    ): void;

    /**
     * Remove all browser-authentication state owned by this vault.
     */
    public function clear(): void;
}
