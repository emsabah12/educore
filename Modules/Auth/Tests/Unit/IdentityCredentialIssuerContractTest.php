<?php

declare(strict_types=1);

namespace Modules\Auth\Tests\Unit;

use Modules\Auth\Application\DTO\IssuedIdentityCredential;
use Modules\Auth\Application\Services\IdentityCredentialIssuer;
use Modules\Auth\Token\Contracts\TokenManagerInterface;
use ReflectionClass;
use ReflectionMethod;
use ReflectionNamedType;
use Tests\TestCase;

final class IdentityCredentialIssuerContractTest extends TestCase
{
    private const USER_ID =
        '11111111-1111-4111-8111-111111111111';

    public function test_identity_credential_issuer_exposes_user_only_contract(): void
    {
        $this->assertTrue(
            class_exists(
                IdentityCredentialIssuer::class,
            ),
            'IdentityCredentialIssuer must exist as the stateless identity credential issuance boundary.',
        );

        $method = new ReflectionMethod(
            IdentityCredentialIssuer::class,
            'issue',
        );

        $this->assertSame(
            1,
            $method->getNumberOfParameters(),
            'Identity credential issuance must require only the authenticated User identifier.',
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

        $this->assertFalse(
            $returnType->allowsNull(),
        );

        $this->assertSame(
            IssuedIdentityCredential::class,
            $returnType->getName(),
        );
    }

    public function test_issued_identity_credential_contains_only_transport_credential_data(): void
    {
        $this->assertTrue(
            class_exists(
                IssuedIdentityCredential::class,
            ),
            'IssuedIdentityCredential must exist before its projection can be inspected.',
        );

        $reflection = new ReflectionClass(
            IssuedIdentityCredential::class,
        );

        $constructor = $reflection->getConstructor();

        $this->assertNotNull(
            $constructor,
        );

        $parameterNames = array_map(
            static fn($parameter): string =>
                $parameter->getName(),
            $constructor->getParameters(),
        );

        $this->assertSame(
            [
                'bearerCredential',
                'expiresInSeconds',
            ],
            $parameterNames,
            'Identity credential DTO must contain only bearer transport data.',
        );

        foreach (
            [
                'userId',
                'tenantId',
                'membershipId',
                'role',
                'permissions',
            ] as $forbiddenProperty
        ) {
            $this->assertFalse(
                $reflection->hasProperty(
                    $forbiddenProperty,
                ),
                sprintf(
                    'Issued identity credential must not contain "%s".',
                    $forbiddenProperty,
                ),
            );
        }
    }

    public function test_identity_credential_issuer_delegates_only_to_explicit_identity_token_issuance(): void
    {
        $tokenManager = $this->createMock(
            TokenManagerInterface::class,
        );

        $tokenManager
            ->expects($this->once())
            ->method('issueIdentityToken')
            ->with(self::USER_ID)
            ->willReturn('identity-bearer');

        $tokenManager
            ->expects($this->once())
            ->method('lifetimeInSeconds')
            ->willReturn(7200);

        $tokenManager
            ->expects($this->never())
            ->method('issueMembershipToken');

        $tokenManager
            ->expects($this->never())
            ->method('issueToken');

        $issuer = new IdentityCredentialIssuer(
            tokenManager: $tokenManager,
        );

        $credential = $issuer->issue(
            self::USER_ID,
        );

        $this->assertInstanceOf(
            IssuedIdentityCredential::class,
            $credential,
        );

        $this->assertSame(
            'identity-bearer',
            $credential->bearerCredential,
        );

        $this->assertSame(
            7200,
            $credential->expiresInSeconds,
        );
    }

    public function test_identity_credential_issuer_rejects_empty_user_identifier_before_token_issuance(): void
    {
        $tokenManager = $this->createMock(
            TokenManagerInterface::class,
        );

        $tokenManager
            ->expects($this->never())
            ->method('issueIdentityToken');

        $issuer = new IdentityCredentialIssuer(
            tokenManager: $tokenManager,
        );

        $this->expectException(
            \InvalidArgumentException::class,
        );

        $issuer->issue(
            '   ',
        );
    }
}
