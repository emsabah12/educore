<?php

declare(strict_types=1);

namespace Modules\Auth\Tests\Unit;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Crypt;
use JsonException;
use Modules\Auth\Services\DeterministicTokenManager;
use Modules\Auth\Token\Contracts\TokenRevocationStoreInterface;
use Tests\TestCase;

final class DeterministicTokenManagerTest extends TestCase
{
    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
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

    /**
     * @throws JsonException
     */
    public function test_issued_token_uses_declared_lifetime(): void
    {
        $now = Carbon::parse(
            '2026-08-04 00:00:00',
            'UTC',
        );

        Carbon::setTestNow($now);

        $manager = $this->createTokenManager();

        $token = $manager->issueToken(
            '019f6e4d-c4cc-72c2-b8d7-cd6faabe6fd2',
            '019f62f3-f5b5-7216-9578-0af9cb3b5b54',
            [
                'membership_id' =>
                '019f6e4d-c67c-7064-a1d5-5261c4162922',
            ],
        );

        $payload = json_decode(
            Crypt::decryptString($token),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );

        $this->assertIsArray($payload);

        $this->assertSame(
            $now->timestamp + $manager->lifetimeInSeconds(),
            $payload['expires_at'],
        );

        $this->assertSame(
            '019f6e4d-c4cc-72c2-b8d7-cd6faabe6fd2',
            $payload['user_id'],
        );

        $this->assertSame(
            '019f62f3-f5b5-7216-9578-0af9cb3b5b54',
            $payload['tenant_id'],
        );

        $this->assertSame(
            '019f6e4d-c67c-7064-a1d5-5261c4162922',
            $payload['membership_id'],
        );
    }

    /**
     * @throws JsonException
     */
    public function test_token_is_rejected_at_expiration_boundary(): void
    {
        $now = Carbon::parse(
            '2026-08-04 00:00:00',
            'UTC',
        );

        Carbon::setTestNow($now);

        $manager = $this->createTokenManager();

        $token = $this->encryptPayload([
            'user_id' =>
            '019f6e4d-c4cc-72c2-b8d7-cd6faabe6fd2',
            'tenant_id' =>
            '019f62f3-f5b5-7216-9578-0af9cb3b5b54',
            'expires_at' => $now->timestamp,
        ]);

        $this->assertNull(
            $manager->validateAndExtract($token),
        );
    }

    /**
     * @throws JsonException
     */
    public function test_token_is_accepted_before_expiration_boundary(): void
    {
        $now = Carbon::parse(
            '2026-08-04 00:00:00',
            'UTC',
        );

        Carbon::setTestNow($now);

        $manager = $this->createTokenManager();

        $expectedPayload = [
            'user_id' =>
            '019f6e4d-c4cc-72c2-b8d7-cd6faabe6fd2',
            'tenant_id' =>
            '019f62f3-f5b5-7216-9578-0af9cb3b5b54',
            'membership_id' =>
            '019f6e4d-c67c-7064-a1d5-5261c4162922',
            'expires_at' => $now->timestamp + 1,
        ];

        $token = $this->encryptPayload(
            $expectedPayload,
        );

        $this->assertSame(
            $expectedPayload,
            $manager->validateAndExtract($token),
        );
    }

    /**
     * @param array<string, mixed> $payload
     *
     * @throws JsonException
     */
    private function encryptPayload(array $payload): string
    {
        return Crypt::encryptString(
            json_encode(
                $payload,
                JSON_THROW_ON_ERROR,
            ),
        );
    }
    public function test_revoked_token_is_rejected(): void
    {
        $revocationStore = $this->createMock(
            TokenRevocationStoreInterface::class,
        );

        $manager = new DeterministicTokenManager(
            $revocationStore,
        );

        $token = $manager->issueToken(
            '11111111-1111-4111-8111-111111111111',
            '22222222-2222-4222-8222-222222222222',
            [
                'membership_id' =>
                '33333333-3333-4333-8333-333333333333',
            ],
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
        );
    }
}
