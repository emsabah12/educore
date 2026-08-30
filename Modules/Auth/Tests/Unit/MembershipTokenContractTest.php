<?php

declare(strict_types=1);

namespace Modules\Auth\Tests\Unit;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Crypt;
use JsonException;
use Modules\Auth\Services\DeterministicTokenManager;
use Modules\Auth\Token\Contracts\TokenManagerInterface;
use Modules\Auth\Token\Contracts\TokenRevocationStoreInterface;
use ReflectionMethod;
use ReflectionNamedType;
use Tests\TestCase;

final class MembershipTokenContractTest extends TestCase
{
    private const USER_ID =
        '11111111-1111-4111-8111-111111111111';

    private const TENANT_ID =
        '22222222-2222-4222-8222-222222222222';

    private const MEMBERSHIP_ID =
        '33333333-3333-4333-8333-333333333333';

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_token_manager_contract_exposes_explicit_membership_issuance(): void
    {
        $this->assertTrue(
            method_exists(
                TokenManagerInterface::class,
                'issueMembershipToken',
            ),
            'TokenManagerInterface must expose explicit Membership Credential issuance.',
        );

        $method = new ReflectionMethod(
            TokenManagerInterface::class,
            'issueMembershipToken',
        );

        $this->assertSame(
            3,
            $method->getNumberOfParameters(),
            'Membership Credential issuance must require User, Tenant, and Membership identifiers only.',
        );

        $expectedParameters = [
            'userUuid',
            'tenantUuid',
            'membershipUuid',
        ];

        foreach (
            $method->getParameters()
            as $index => $parameter
        ) {
            $this->assertSame(
                $expectedParameters[$index],
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
        }

        $returnType = $method->getReturnType();

        $this->assertInstanceOf(
            ReflectionNamedType::class,
            $returnType,
        );

        $this->assertSame(
            'string',
            $returnType->getName(),
        );
    }

    /**
     * @throws JsonException
     */
    public function test_issued_membership_token_contains_only_canonical_membership_claims(): void
    {
        $now = Carbon::parse(
            '2026-08-30 07:00:00',
            'UTC',
        );

        Carbon::setTestNow($now);

        $manager = $this->createTokenManager();

        $this->assertTrue(
            method_exists(
                $manager,
                'issueMembershipToken',
            ),
            'DeterministicTokenManager must implement explicit Membership Credential issuance.',
        );

        $token = $manager->issueMembershipToken(
            self::USER_ID,
            self::TENANT_ID,
            self::MEMBERSHIP_ID,
        );

        $payload = json_decode(
            Crypt::decryptString($token),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );

        $this->assertSame(
            [
                'credential_type' => 'membership',
                'user_id' => self::USER_ID,
                'tenant_id' => self::TENANT_ID,
                'membership_id' => self::MEMBERSHIP_ID,
                'expires_at' => $now->timestamp
                    + $manager->lifetimeInSeconds(),
            ],
            $payload,
            'Membership Credential must carry only canonical Membership Context claims.',
        );
    }

    public function test_membership_token_is_accepted_by_canonical_validation(): void
    {
        $now = Carbon::parse(
            '2026-08-30 07:00:00',
            'UTC',
        );

        Carbon::setTestNow($now);

        $manager = $this->createTokenManager();

        $this->assertTrue(
            method_exists(
                $manager,
                'issueMembershipToken',
            ),
            'DeterministicTokenManager must implement explicit Membership Credential issuance.',
        );

        $token = $manager->issueMembershipToken(
            self::USER_ID,
            self::TENANT_ID,
            self::MEMBERSHIP_ID,
        );

        $this->assertSame(
            [
                'credential_type' => 'membership',
                'user_id' => self::USER_ID,
                'tenant_id' => self::TENANT_ID,
                'membership_id' => self::MEMBERSHIP_ID,
                'expires_at' => $now->timestamp
                    + $manager->lifetimeInSeconds(),
            ],
            $manager->validateAndExtract(
                $token,
            ),
            'Canonical validation must accept a valid typed Membership Credential.',
        );
    }

    public function test_membership_token_exposes_expiration_for_revocation_lifecycle(): void
    {
        $now = Carbon::parse(
            '2026-08-30 07:00:00',
            'UTC',
        );

        Carbon::setTestNow($now);

        $manager = $this->createTokenManager();

        $this->assertTrue(
            method_exists(
                $manager,
                'issueMembershipToken',
            ),
            'DeterministicTokenManager must implement explicit Membership Credential issuance.',
        );

        $token = $manager->issueMembershipToken(
            self::USER_ID,
            self::TENANT_ID,
            self::MEMBERSHIP_ID,
        );

        $this->assertSame(
            $now->timestamp
                + $manager->lifetimeInSeconds(),
            $manager->expiresAtForRevocation(
                $token,
            ),
            'Membership Credential must participate in the canonical revocation lifecycle.',
        );
    }

    public function test_revoked_membership_token_is_rejected(): void
    {
        $revocationStore = $this->createMock(
            TokenRevocationStoreInterface::class,
        );

        $manager = new DeterministicTokenManager(
            $revocationStore,
        );

        $this->assertTrue(
            method_exists(
                $manager,
                'issueMembershipToken',
            ),
            'DeterministicTokenManager must implement explicit Membership Credential issuance.',
        );

        $token = $manager->issueMembershipToken(
            self::USER_ID,
            self::TENANT_ID,
            self::MEMBERSHIP_ID,
        );

        $revocationStore
            ->expects($this->once())
            ->method('isRevoked')
            ->with($token)
            ->willReturn(true);

        $this->assertNull(
            $manager->validateAndExtract(
                $token,
            ),
            'Revoked Membership Credentials must fail closed.',
        );
    }

    private function createTokenManager(): DeterministicTokenManager
    {
        $revocationStore = $this->createStub(
            TokenRevocationStoreInterface::class,
        );

        $revocationStore
            ->method('isRevoked')
            ->willReturn(false);

        return new DeterministicTokenManager(
            $revocationStore,
        );
    }
}
