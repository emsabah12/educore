<?php

declare(strict_types=1);

namespace Modules\Auth\Tests\Unit;

use Illuminate\Contracts\Session\Session;
use Modules\Auth\BrowserSession\Contracts\BrowserSessionCredentialVaultInterface;
use Modules\Auth\BrowserSession\Infrastructure\SessionCredentialVault;
use Modules\Core\Support\Uuid\UuidV7;
use ReflectionMethod;
use ReflectionNamedType;
use Tests\TestCase;

final class BrowserSessionFreshIdentityContractTest extends TestCase
{
    public function test_browser_session_vault_exposes_explicit_fresh_identity_boundary(): void
    {
        $this->assertTrue(
            method_exists(
                BrowserSessionCredentialVaultInterface::class,
                'establishFreshIdentity',
            ),
            'BrowserSession credential vault must expose an explicit fresh identity establishment boundary.',
        );

        if (
            ! method_exists(
                BrowserSessionCredentialVaultInterface::class,
                'establishFreshIdentity',
            )
        ) {
            return;
        }

        $method = new ReflectionMethod(
            BrowserSessionCredentialVaultInterface::class,
            'establishFreshIdentity',
        );

        $this->assertSame(
            1,
            $method->getNumberOfParameters(),
        );

        $parameter = $method->getParameters()[0];

        $this->assertSame(
            'userId',
            $parameter->getName(),
        );

        $parameterType = $parameter->getType();

        $this->assertInstanceOf(
            ReflectionNamedType::class,
            $parameterType,
        );

        $this->assertSame(
            'string',
            $parameterType->getName(),
        );

        $returnType = $method->getReturnType();

        $this->assertInstanceOf(
            ReflectionNamedType::class,
            $returnType,
        );

        $this->assertSame(
            'void',
            $returnType->getName(),
        );
    }

    public function test_fresh_identity_establishment_discards_same_user_membership_credentials(): void
    {
        if (
            ! method_exists(
                SessionCredentialVault::class,
                'establishFreshIdentity',
            )
        ) {
            $this->fail(
                'SessionCredentialVault must implement establishFreshIdentity().',
            );
        }

        config([
            'session.driver' => 'array',
        ]);

        /** @var Session $session */
        $session = $this->app->make(
            'session.store',
        );

        $vault = new SessionCredentialVault(
            $session,
        );

        $userId = UuidV7::generate();
        $membershipId = UuidV7::generate();

        /*
         * Simulate an already-authenticated BrowserSession holding a
         * Membership credential for this same User.
         */
        $vault->establishForUser(
            $userId,
        );

        $vault->storeMembershipCredential(
            $membershipId,
            'existing-membership-bearer',
        );

        $this->assertSame(
            $userId,
            $vault->userId(),
        );

        $this->assertSame(
            'existing-membership-bearer',
            $vault->credentialForMembership(
                $membershipId,
            ),
        );

        /*
         * Fresh credential verification for the same User must establish a
         * clean identity session rather than inherit prior Tenant context.
         */
        $vault->establishFreshIdentity(
            $userId,
        );

        $this->assertSame(
            $userId,
            $vault->userId(),
        );

        $this->assertNull(
            $vault->credentialForMembership(
                $membershipId,
            ),
            'Fresh browser authentication must discard same-user Membership credentials.',
        );
    }

    public function test_fresh_identity_establishment_produces_empty_membership_inventory(): void
    {
        if (
            ! method_exists(
                SessionCredentialVault::class,
                'establishFreshIdentity',
            )
        ) {
            $this->fail(
                'SessionCredentialVault must implement establishFreshIdentity().',
            );
        }

        config([
            'session.driver' => 'array',
        ]);

        /** @var Session $session */
        $session = $this->app->make(
            'session.store',
        );

        $vault = new SessionCredentialVault(
            $session,
        );

        $oldUserId = UuidV7::generate();
        $newUserId = UuidV7::generate();
        $membershipId = UuidV7::generate();

        $vault->establishForUser(
            $oldUserId,
        );

        $vault->storeMembershipCredential(
            $membershipId,
            'old-membership-bearer',
        );

        $vault->establishFreshIdentity(
            $newUserId,
        );

        $this->assertSame(
            $newUserId,
            $vault->userId(),
        );

        $browserAuthState = $session->get(
            'educore.browser_auth',
        );

        $this->assertIsArray(
            $browserAuthState,
        );

        $this->assertSame(
            $newUserId,
            $browserAuthState['user_id']
                ?? null,
        );

        $this->assertSame(
            [],
            $browserAuthState['membership_credentials']
                ?? null,
            'Fresh BrowserSession identity must start with an empty Membership credential inventory.',
        );
    }
}
