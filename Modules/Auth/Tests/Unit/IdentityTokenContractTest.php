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

final class IdentityTokenContractTest extends TestCase
{
    private const USER_ID =
        '11111111-1111-4111-8111-111111111111';

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_token_manager_contract_exposes_identity_issuance_without_tenant_context(): void
    {
        $this->assertTrue(
            method_exists(
                TokenManagerInterface::class,
                'issueIdentityToken',
            ),
            'TokenManagerInterface must expose identity token issuance without Tenant context.',
        );

        $method = new ReflectionMethod(
            TokenManagerInterface::class,
            'issueIdentityToken',
        );

        $this->assertSame(
            1,
            $method->getNumberOfParameters(),
            'Identity token issuance must require only canonical User identity.',
        );

        $parameter = $method->getParameters()[0];

        $this->assertSame(
            'userUuid',
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
            'string',
            $returnType->getName(),
        );
    }

    /**
     * @throws JsonException
     */
    public function test_issued_identity_token_contains_only_canonical_identity_claims(): void
    {
        $now = Carbon::parse(
            '2026-08-30 06:30:00',
            'UTC',
        );

        Carbon::setTestNow($now);

        $manager = $this->createTokenManager();

        $this->assertTrue(
            method_exists(
                $manager,
                'issueIdentityToken',
            ),
            'DeterministicTokenManager must implement identity token issuance.',
        );

        $token = $manager->issueIdentityToken(
            self::USER_ID,
        );

        $payload = json_decode(
            Crypt::decryptString($token),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );

        $this->assertSame(
            [
                'credential_type' => 'identity',
                'user_id' => self::USER_ID,
                'expires_at' => $now->timestamp
                    + $manager->lifetimeInSeconds(),
            ],
            $payload,
            'Identity bearer must not carry Tenant, Membership, role, or permission context.',
        );
    }

    public function test_identity_token_is_accepted_by_canonical_validation(): void
    {
        $now = Carbon::parse(
            '2026-08-30 06:30:00',
            'UTC',
        );

        Carbon::setTestNow($now);

        $manager = $this->createTokenManager();

        $this->assertTrue(
            method_exists(
                $manager,
                'issueIdentityToken',
            ),
            'DeterministicTokenManager must implement identity token issuance.',
        );

        $token = $manager->issueIdentityToken(
            self::USER_ID,
        );

        $this->assertSame(
            [
                'credential_type' => 'identity',
                'user_id' => self::USER_ID,
                'expires_at' => $now->timestamp
                    + $manager->lifetimeInSeconds(),
            ],
            $manager->validateAndExtract(
                $token,
            ),
            'Canonical token validation must accept a valid Identity Credential without Tenant context.',
        );
    }

    public function test_identity_token_exposes_expiration_for_revocation_lifecycle(): void
    {
        $now = Carbon::parse(
            '2026-08-30 06:30:00',
            'UTC',
        );

        Carbon::setTestNow($now);

        $manager = $this->createTokenManager();

        $this->assertTrue(
            method_exists(
                $manager,
                'issueIdentityToken',
            ),
            'DeterministicTokenManager must implement identity token issuance.',
        );

        $token = $manager->issueIdentityToken(
            self::USER_ID,
        );

        $this->assertSame(
            $now->timestamp
                + $manager->lifetimeInSeconds(),
            $manager->expiresAtForRevocation(
                $token,
            ),
            'Identity Credential must participate in the canonical revocation lifecycle.',
        );
    }

    public function test_revoked_identity_token_is_rejected(): void
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
                'issueIdentityToken',
            ),
            'DeterministicTokenManager must implement identity token issuance.',
        );

        $token = $manager->issueIdentityToken(
            self::USER_ID,
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
            'Revoked Identity Credentials must fail closed.',
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
