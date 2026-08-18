<?php

declare(strict_types=1);

namespace Modules\Auth\Tests\Unit;

use Illuminate\Session\ArraySessionHandler;
use Illuminate\Session\Store;
use InvalidArgumentException;
use LogicException;
use Modules\Auth\BrowserSession\Contracts\BrowserSessionCredentialVaultInterface;
use Modules\Auth\BrowserSession\Infrastructure\SessionCredentialVault;
use Modules\Core\Support\Uuid\UuidV7;
use Tests\TestCase;

final class SessionCredentialVaultTest extends TestCase
{
    public function test_it_keeps_membership_credentials_independent(): void
    {
        $vault = $this->createVault();
        $userId = UuidV7::generate();
        $membershipAId = UuidV7::generate();
        $membershipBId = UuidV7::generate();

        $vault->establishForUser($userId);
        $vault->storeMembershipCredential(
            $membershipAId,
            'bearer-a',
        );
        $vault->storeMembershipCredential(
            $membershipBId,
            'bearer-b',
        );

        $this->assertSame($userId, $vault->userId());
        $this->assertSame(
            'bearer-a',
            $vault->credentialForMembership($membershipAId),
        );
        $this->assertSame(
            'bearer-b',
            $vault->credentialForMembership($membershipBId),
        );
    }

    public function test_reestablishing_same_user_preserves_credentials(): void
    {
        $vault = $this->createVault();
        $userId = UuidV7::generate();
        $membershipId = UuidV7::generate();

        $vault->establishForUser($userId);
        $vault->storeMembershipCredential(
            $membershipId,
            'bearer-a',
        );

        $vault->establishForUser($userId);

        $this->assertSame(
            'bearer-a',
            $vault->credentialForMembership($membershipId),
        );
    }

    public function test_establishing_different_user_discards_previous_credentials(): void
    {
        $vault = $this->createVault();
        $firstUserId = UuidV7::generate();
        $secondUserId = UuidV7::generate();
        $membershipId = UuidV7::generate();

        $vault->establishForUser($firstUserId);
        $vault->storeMembershipCredential(
            $membershipId,
            'first-user-bearer',
        );

        $vault->establishForUser($secondUserId);

        $this->assertSame($secondUserId, $vault->userId());
        $this->assertNull(
            $vault->credentialForMembership($membershipId),
        );
    }

    public function test_credential_cannot_be_stored_without_session_owner(): void
    {
        $vault = $this->createVault();

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage(
            'Browser session credential vault has no authenticated user owner.',
        );

        $vault->storeMembershipCredential(
            UuidV7::generate(),
            'bearer-a',
        );
    }

    public function test_forgetting_one_membership_preserves_other_credentials(): void
    {
        $vault = $this->createVault();
        $membershipAId = UuidV7::generate();
        $membershipBId = UuidV7::generate();

        $vault->establishForUser(UuidV7::generate());
        $vault->storeMembershipCredential(
            $membershipAId,
            'bearer-a',
        );
        $vault->storeMembershipCredential(
            $membershipBId,
            'bearer-b',
        );

        $vault->forgetMembershipCredential($membershipAId);

        $this->assertNull(
            $vault->credentialForMembership($membershipAId),
        );
        $this->assertSame(
            'bearer-b',
            $vault->credentialForMembership($membershipBId),
        );
    }

    public function test_clear_removes_owner_and_credentials(): void
    {
        $vault = $this->createVault();
        $membershipId = UuidV7::generate();

        $vault->establishForUser(UuidV7::generate());
        $vault->storeMembershipCredential(
            $membershipId,
            'bearer-a',
        );

        $vault->clear();

        $this->assertNull($vault->userId());
        $this->assertNull(
            $vault->credentialForMembership($membershipId),
        );
    }

    public function test_invalid_user_id_is_rejected(): void
    {
        $vault = $this->createVault();

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage(
            'userId must be a valid UUID v7.',
        );

        $vault->establishForUser('not-a-uuid');
    }

    public function test_invalid_membership_id_is_rejected(): void
    {
        $vault = $this->createVault();

        $vault->establishForUser(UuidV7::generate());

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage(
            'membershipId must be a valid UUID v7.',
        );

        $vault->storeMembershipCredential(
            'not-a-uuid',
            'bearer-a',
        );
    }

    public function test_empty_bearer_credential_is_rejected(): void
    {
        $vault = $this->createVault();

        $vault->establishForUser(UuidV7::generate());

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage(
            'bearerCredential must not be empty.',
        );

        $vault->storeMembershipCredential(
            UuidV7::generate(),
            '   ',
        );
    }

    public function test_container_resolves_browser_session_vault_contract(): void
    {
        $resolved = $this->app->make(
            BrowserSessionCredentialVaultInterface::class,
        );

        $this->assertInstanceOf(
            SessionCredentialVault::class,
            $resolved,
        );
    }

    private function createVault(): SessionCredentialVault
    {
        $session = new Store(
            'educore-test-session',
            new ArraySessionHandler(120),
            null,
            'json',
        );

        return new SessionCredentialVault(
            $session,
        );
    }
}
