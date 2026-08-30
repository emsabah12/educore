<?php

declare(strict_types=1);

namespace Modules\Auth\Tests\Unit;

use Modules\Auth\Application\AuthenticationChannel;
use Modules\Auth\Application\Services\GlobalAuthenticationService;
use Modules\Auth\Authentication\Contracts\AuthenticationRepositoryInterface;
use Modules\Auth\Authentication\Contracts\PasswordVerifierInterface;
use Modules\Core\Governance\Audit\Contracts\AuditTrailServiceInterface;
use Tests\TestCase;

final class GlobalAuthenticationMalformedProjectionTest extends TestCase
{
    private const USER_ID =
        '11111111-1111-4111-8111-111111111111';

    private const PERSON_ID =
        '22222222-2222-4222-8222-222222222222';

    private const DUMMY_PASSWORD_HASH =
        'configured-dummy-password-hash';

    public function test_missing_password_hash_cannot_authenticate_even_if_dummy_hash_matches(): void
    {
        $projection = $this->validProjection();

        unset(
            $projection['password_hash'],
        );

        $repository = $this->createMock(
            AuthenticationRepositoryInterface::class,
        );

        $repository
            ->expects($this->once())
            ->method('findActiveByLoginIdentifier')
            ->with('ahmad')
            ->willReturn($projection);

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
            ->willReturn(true);

        $service = $this->service(
            repository: $repository,
            passwordVerifier: $passwordVerifier,
        );

        $this->assertNull(
            $service->authenticate(
                identifier: 'ahmad',
                password: 'secret123',
                channel: AuthenticationChannel::MOBILE_API,
            ),
            'A User with a missing canonical password hash must fail authentication even when the dummy hash comparison returns true.',
        );
    }

    public function test_malformed_password_hash_cannot_authenticate_even_if_dummy_hash_matches(): void
    {
        $projection = $this->validProjection();

        $projection['password_hash'] = [
            'malformed',
        ];

        $repository = $this->createMock(
            AuthenticationRepositoryInterface::class,
        );

        $repository
            ->expects($this->once())
            ->method('findActiveByLoginIdentifier')
            ->with('ahmad@example.com')
            ->willReturn($projection);

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
            ->willReturn(true);

        $service = $this->service(
            repository: $repository,
            passwordVerifier: $passwordVerifier,
        );

        $this->assertNull(
            $service->authenticate(
                identifier: 'ahmad@example.com',
                password: 'secret123',
                channel: AuthenticationChannel::BROWSER_SESSION,
            ),
            'A User with a malformed canonical password hash must never authenticate through the dummy verification path.',
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function validProjection(): array
    {
        return [
            'user_id' => self::USER_ID,
            'person_id' => self::PERSON_ID,
            'person_name' => 'Ahmad Example',
            'email' => 'ahmad@example.com',
            'username' => 'ahmad',
            'password_hash' => 'stored-real-password-hash',
            'is_superadmin' => false,
            'user_status' => 'ACTIVE',
        ];
    }

    private function service(
        AuthenticationRepositoryInterface $repository,
        PasswordVerifierInterface $passwordVerifier,
    ): GlobalAuthenticationService {
        return new GlobalAuthenticationService(
            authRepository: $repository,
            passwordVerifier: $passwordVerifier,
            auditTrail: $this->createStub(
                AuditTrailServiceInterface::class,
            ),
            dummyPasswordHash: self::DUMMY_PASSWORD_HASH,
        );
    }
}
