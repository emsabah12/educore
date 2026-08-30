<?php

declare(strict_types=1);

namespace Modules\Auth\Tests\Unit;

use Modules\Auth\Application\AuthenticationChannel;
use Modules\Auth\Application\DTO\AuthenticatedGlobalIdentity;
use Modules\Auth\Application\Services\GlobalAuthenticationService;
use Modules\Auth\Authentication\Contracts\AuthenticationRepositoryInterface;
use Modules\Auth\Authentication\Contracts\PasswordVerifierInterface;
use Modules\Core\Governance\Audit\Contracts\AuditTrailServiceInterface;
use ReflectionMethod;
use ReflectionNamedType;
use Tests\TestCase;

final class GlobalAuthenticationServiceTest extends TestCase
{
    private const USER_ID =
        '11111111-1111-4111-8111-111111111111';

    private const PERSON_ID =
        '22222222-2222-4222-8222-222222222222';

    private const DUMMY_PASSWORD_HASH =
        'configured-dummy-password-hash';

    public function test_global_authentication_service_exposes_tenant_independent_contract(): void
    {
        $this->assertTrue(
            class_exists(
                GlobalAuthenticationService::class,
            ),
            'GlobalAuthenticationService must exist as the canonical global credential verification boundary.',
        );

        $method = new ReflectionMethod(
            GlobalAuthenticationService::class,
            'authenticate',
        );

        $this->assertSame(
            3,
            $method->getNumberOfParameters(),
            'Global authentication must require only identifier, password, and authentication channel.',
        );

        $parameters = $method->getParameters();

        $this->assertSame(
            'identifier',
            $parameters[0]->getName(),
        );

        $this->assertSame(
            'password',
            $parameters[1]->getName(),
        );

        $this->assertSame(
            'channel',
            $parameters[2]->getName(),
        );

        $identifierType = $parameters[0]->getType();
        $passwordType = $parameters[1]->getType();
        $channelType = $parameters[2]->getType();

        $this->assertInstanceOf(
            ReflectionNamedType::class,
            $identifierType,
        );

        $this->assertSame(
            'string',
            $identifierType->getName(),
        );

        $this->assertInstanceOf(
            ReflectionNamedType::class,
            $passwordType,
        );

        $this->assertSame(
            'string',
            $passwordType->getName(),
        );

        $this->assertInstanceOf(
            ReflectionNamedType::class,
            $channelType,
        );

        $this->assertSame(
            AuthenticationChannel::class,
            $channelType->getName(),
        );

        $returnType = $method->getReturnType();

        $this->assertInstanceOf(
            ReflectionNamedType::class,
            $returnType,
        );

        $this->assertTrue(
            $returnType->allowsNull(),
        );

        $this->assertSame(
            AuthenticatedGlobalIdentity::class,
            $returnType->getName(),
        );
    }

    public function test_valid_credentials_return_global_identity_without_membership_or_tenant_context(): void
    {
        $repository = $this->createMock(
            AuthenticationRepositoryInterface::class,
        );

        $repository
            ->expects($this->once())
            ->method('findActiveByLoginIdentifier')
            ->with('ahmad')
            ->willReturn(
                $this->activeUserProjection(),
            );

        $passwordVerifier = $this->createMock(
            PasswordVerifierInterface::class,
        );

        $passwordVerifier
            ->expects($this->once())
            ->method('verify')
            ->with(
                'secret123',
                'stored-real-password-hash',
            )
            ->willReturn(true);

        $auditTrail = $this->createMock(
            AuditTrailServiceInterface::class,
        );

        $auditTrail
            ->expects($this->never())
            ->method('log');

        $service = $this->service(
            repository: $repository,
            passwordVerifier: $passwordVerifier,
            auditTrail: $auditTrail,
        );

        $identity = $service->authenticate(
            identifier: 'ahmad',
            password: 'secret123',
            channel: AuthenticationChannel::MOBILE_API,
        );

        $this->assertInstanceOf(
            AuthenticatedGlobalIdentity::class,
            $identity,
        );

        $this->assertSame(
            self::USER_ID,
            $identity->userId,
        );

        $this->assertSame(
            self::PERSON_ID,
            $identity->personId,
        );

        $this->assertSame(
            'Ahmad Example',
            $identity->name,
        );

        $this->assertSame(
            'ahmad@example.com',
            $identity->email,
        );

        $this->assertSame(
            'ahmad',
            $identity->username,
        );

        $this->assertTrue(
            $identity->isSuperadmin,
        );

        $this->assertFalse(
            property_exists(
                $identity,
                'passwordHash',
            ),
            'Authenticated identity projection must not expose password hashes.',
        );

        $this->assertFalse(
            property_exists(
                $identity,
                'tenantId',
            ),
            'Global authentication must not return Tenant context.',
        );

        $this->assertFalse(
            property_exists(
                $identity,
                'membershipId',
            ),
            'Global authentication must not return Membership context.',
        );
    }

    public function test_unknown_identifier_still_executes_password_verification_against_dummy_hash(): void
    {
        $repository = $this->createMock(
            AuthenticationRepositoryInterface::class,
        );

        $repository
            ->expects($this->once())
            ->method('findActiveByLoginIdentifier')
            ->with('missing-user')
            ->willReturn(null);

        $passwordVerifier = $this->createMock(
            PasswordVerifierInterface::class,
        );

        $passwordVerifier
            ->expects($this->once())
            ->method('verify')
            ->with(
                'secret123',
                self::DUMMY_PASSWORD_HASH,
            )
            ->willReturn(false);

        $auditTrail = $this->failureAuditExpectation(
            channel: AuthenticationChannel::MOBILE_API,
            identifierType: 'username',
            forbiddenIdentifier: 'missing-user',
        );

        $service = $this->service(
            repository: $repository,
            passwordVerifier: $passwordVerifier,
            auditTrail: $auditTrail,
        );

        $this->assertNull(
            $service->authenticate(
                identifier: 'missing-user',
                password: 'secret123',
                channel: AuthenticationChannel::MOBILE_API,
            ),
        );
    }

    public function test_wrong_password_uses_same_generic_failure_boundary(): void
    {
        $repository = $this->createMock(
            AuthenticationRepositoryInterface::class,
        );

        $repository
            ->expects($this->once())
            ->method('findActiveByLoginIdentifier')
            ->with('ahmad@example.com')
            ->willReturn(
                $this->activeUserProjection(),
            );

        $passwordVerifier = $this->createMock(
            PasswordVerifierInterface::class,
        );

        $passwordVerifier
            ->expects($this->once())
            ->method('verify')
            ->with(
                'wrong-password',
                'stored-real-password-hash',
            )
            ->willReturn(false);

        $auditTrail = $this->failureAuditExpectation(
            channel: AuthenticationChannel::BROWSER_SESSION,
            identifierType: 'email',
            forbiddenIdentifier: 'ahmad@example.com',
        );

        $service = $this->service(
            repository: $repository,
            passwordVerifier: $passwordVerifier,
            auditTrail: $auditTrail,
        );

        $this->assertNull(
            $service->authenticate(
                identifier: 'ahmad@example.com',
                password: 'wrong-password',
                channel: AuthenticationChannel::BROWSER_SESSION,
            ),
        );
    }

    public function test_nullable_username_is_preserved_in_authenticated_identity(): void
    {
        $projection = $this->activeUserProjection();
        $projection['username'] = null;

        $repository = $this->createMock(
            AuthenticationRepositoryInterface::class,
        );

        $repository
            ->method('findActiveByLoginIdentifier')
            ->willReturn($projection);

        $passwordVerifier = $this->createMock(
            PasswordVerifierInterface::class,
        );

        $passwordVerifier
            ->method('verify')
            ->willReturn(true);

        $auditTrail = $this->createStub(
            AuditTrailServiceInterface::class,
        );

        $identity = $this->service(
            repository: $repository,
            passwordVerifier: $passwordVerifier,
            auditTrail: $auditTrail,
        )->authenticate(
            identifier: 'ahmad@example.com',
            password: 'secret123',
            channel: AuthenticationChannel::MOBILE_API,
        );

        $this->assertInstanceOf(
            AuthenticatedGlobalIdentity::class,
            $identity,
        );

        $this->assertNull(
            $identity->username,
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function activeUserProjection(): array
    {
        return [
            'user_id' => self::USER_ID,
            'person_id' => self::PERSON_ID,
            'person_name' => 'Ahmad Example',
            'email' => 'ahmad@example.com',
            'username' => 'ahmad',
            'password_hash' => 'stored-real-password-hash',
            'is_superadmin' => true,
            'user_status' => 'ACTIVE',
        ];
    }

    private function service(
        AuthenticationRepositoryInterface $repository,
        PasswordVerifierInterface $passwordVerifier,
        AuditTrailServiceInterface $auditTrail,
    ): GlobalAuthenticationService {
        return new GlobalAuthenticationService(
            authRepository: $repository,
            passwordVerifier: $passwordVerifier,
            auditTrail: $auditTrail,
            dummyPasswordHash: self::DUMMY_PASSWORD_HASH,
        );
    }

    private function failureAuditExpectation(
        AuthenticationChannel $channel,
        string $identifierType,
        string $forbiddenIdentifier,
    ): AuditTrailServiceInterface {
        $auditTrail = $this->createMock(
            AuditTrailServiceInterface::class,
        );

        $auditTrail
            ->expects($this->once())
            ->method('log')
            ->with(
                'auth.login_failed',
                $this->callback(
                    static fn(string $description): bool =>
                        ! str_contains(
                            $description,
                            $forbiddenIdentifier,
                        ),
                ),
                null,
                null,
                [
                    'channel' => $channel->value,
                    'identifier_type' => $identifierType,
                ],
            );

        return $auditTrail;
    }
}
