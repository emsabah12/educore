<?php

declare(strict_types=1);

namespace Modules\Auth\BrowserSession\Infrastructure;

use Illuminate\Contracts\Session\Session;
use InvalidArgumentException;
use LogicException;
use Modules\Auth\BrowserSession\Contracts\BrowserSessionAuthenticationCredentialProviderInterface;
use Modules\Auth\BrowserSession\Contracts\BrowserSessionCredentialInventoryInterface;
use Modules\Auth\BrowserSession\Contracts\BrowserSessionCredentialVaultInterface;
use Modules\Core\Support\Uuid\UuidV7;

final class SessionCredentialVault implements BrowserSessionAuthenticationCredentialProviderInterface, BrowserSessionCredentialInventoryInterface, BrowserSessionCredentialVaultInterface
{
    private const SESSION_KEY = 'educore.browser_auth';

    private const USER_ID_KEY = 'user_id';

    private const MEMBERSHIP_CREDENTIALS_KEY = 'membership_credentials';

    public function __construct(
        private readonly Session $session,
    ) {}

    public function establishForUser(string $userId): void
    {
        $this->assertUuidV7($userId, 'userId');

        $state = $this->readState();
        $currentUserId = $state[self::USER_ID_KEY];

        if ($currentUserId !== null && $currentUserId !== $userId) {
            $state = $this->emptyState();
        }

        $state[self::USER_ID_KEY] = $userId;

        $this->writeState($state);
    }

    public function establishFreshIdentity(string $userId): void
    {
        $this->assertUuidV7($userId, 'userId');

        /*
         * Fresh authentication must never inherit Membership/Tenant
         * credentials from an earlier authenticated browser state,
         * including an earlier session owned by this same User.
         */
        $state = $this->emptyState();

        $state[self::USER_ID_KEY] = $userId;

        $this->writeState($state);
    }

    public function userId(): ?string
    {
        return $this->readState()[self::USER_ID_KEY];
    }

    public function storeMembershipCredential(
        string $membershipId,
        string $bearerCredential,
    ): void {
        $this->assertUuidV7($membershipId, 'membershipId');
        $this->assertBearerCredential($bearerCredential);

        $state = $this->readState();

        if ($state[self::USER_ID_KEY] === null) {
            throw new LogicException(
                'Browser session credential vault has no authenticated user owner.',
            );
        }

        $state[self::MEMBERSHIP_CREDENTIALS_KEY][$membershipId] =
            $bearerCredential;

        $this->writeState($state);
    }

    public function credentialForMembership(
        string $membershipId,
    ): ?string {
        $this->assertUuidV7($membershipId, 'membershipId');

        $credentials = $this->readState()[
            self::MEMBERSHIP_CREDENTIALS_KEY
        ];

        return $credentials[$membershipId] ?? null;
    }

    public function credentialForAuthentication(): ?string
    {
        $credentials = $this->readState()[
            self::MEMBERSHIP_CREDENTIALS_KEY
        ];

        foreach ($credentials as $credential) {
            return $credential;
        }

        return null;
    }

    public function credentialsForRevocation(): array
    {
        return $this->readState()[
            self::MEMBERSHIP_CREDENTIALS_KEY
        ];
    }

    public function forgetMembershipCredential(
        string $membershipId,
    ): void {
        $this->assertUuidV7($membershipId, 'membershipId');

        $state = $this->readState();

        unset(
            $state[self::MEMBERSHIP_CREDENTIALS_KEY][$membershipId],
        );

        $this->writeState($state);
    }

    public function clear(): void
    {
        $this->session->forget(self::SESSION_KEY);
    }

    /**
     * @return array{
     *     user_id: string|null,
     *     membership_credentials: array<string, string>
     * }
     */
    private function readState(): array
    {
        $storedState = $this->session->get(
            self::SESSION_KEY,
            [],
        );

        if (! is_array($storedState)) {
            return $this->emptyState();
        }

        $userId = $storedState[self::USER_ID_KEY] ?? null;

        if (! is_string($userId) || ! UuidV7::validate($userId)) {
            $userId = null;
        }

        $storedCredentials = $storedState[
            self::MEMBERSHIP_CREDENTIALS_KEY
        ] ?? [];

        if (! is_array($storedCredentials)) {
            $storedCredentials = [];
        }

        $credentials = [];

        foreach ($storedCredentials as $membershipId => $credential) {
            if (
                ! is_string($membershipId)
                || ! UuidV7::validate($membershipId)
                || ! is_string($credential)
                || trim($credential) === ''
            ) {
                continue;
            }

            $credentials[$membershipId] = $credential;
        }

        if ($userId === null) {
            $credentials = [];
        }

        return [
            self::USER_ID_KEY => $userId,
            self::MEMBERSHIP_CREDENTIALS_KEY => $credentials,
        ];
    }

    /**
     * @param array{
     *     user_id: string|null,
     *     membership_credentials: array<string, string>
     * } $state
     */
    private function writeState(array $state): void
    {
        $this->session->put(
            self::SESSION_KEY,
            $state,
        );
    }

    /**
     * @return array{
     *     user_id: null,
     *     membership_credentials: array<string, string>
     * }
     */
    private function emptyState(): array
    {
        return [
            self::USER_ID_KEY => null,
            self::MEMBERSHIP_CREDENTIALS_KEY => [],
        ];
    }

    private function assertUuidV7(
        string $value,
        string $parameter,
    ): void {
        if (UuidV7::validate($value)) {
            return;
        }

        throw new InvalidArgumentException(
            sprintf(
                '%s must be a valid UUID v7.',
                $parameter,
            ),
        );
    }

    private function assertBearerCredential(
        string $bearerCredential,
    ): void {
        if (trim($bearerCredential) !== '') {
            return;
        }

        throw new InvalidArgumentException(
            'bearerCredential must not be empty.',
        );
    }
}
