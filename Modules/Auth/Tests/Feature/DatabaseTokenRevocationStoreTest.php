<?php

declare(strict_types=1);

namespace Modules\Auth\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Modules\Auth\Token\Contracts\TokenRevocationStoreInterface;
use Modules\Auth\Token\Persistence\DatabaseTokenRevocationStore;
use Tests\TestCase;

final class DatabaseTokenRevocationStoreTest extends TestCase
{
    use RefreshDatabase;

    public function test_revoked_token_is_detected_without_storing_raw_token(): void
    {
        $store = $this->app->make(
            TokenRevocationStoreInterface::class,
        );

        $token = 'sensitive-bearer-token';

        $store->revoke(
            token: $token,
            expiresAt: now()->timestamp + 3600,
        );

        $this->assertTrue(
            $store->isRevoked($token),
        );

        $storedHash = DB::table(
            'auth_token_revocations',
        )->value(
            'token_hash',
        );

        $this->assertIsString(
            $storedHash,
        );

        $this->assertSame(
            hash('sha256', $token),
            $storedHash,
        );

        $this->assertNotSame(
            $token,
            $storedHash,
        );
    }

    public function test_revoking_same_token_is_idempotent(): void
    {
        $store = $this->app->make(
            TokenRevocationStoreInterface::class,
        );

        $token = 'idempotent-bearer-token';

        $expiresAt = now()->timestamp + 3600;

        $tokenHash = hash('sha256', $token);

        $store->revoke(
            $token,
            $expiresAt,
        );

        $firstRevocation = DB::table('auth_token_revocations')
            ->where('token_hash', $tokenHash)
            ->first();

        $this->assertNotNull($firstRevocation);

        $this->travel(1)->seconds();

        $store->revoke(
            $token,
            $expiresAt + 600,
        );

        $secondRevocation = DB::table('auth_token_revocations')
            ->where('token_hash', $tokenHash)
            ->first();

        $this->assertNotNull($secondRevocation);

        $this->assertSame(
            1,
            DB::table('auth_token_revocations')
                ->where('token_hash', $tokenHash)
                ->count(),
        );

        $this->assertSame(
            (int) $firstRevocation->expires_at,
            (int) $secondRevocation->expires_at,
        );

        $this->assertSame(
            (string) $firstRevocation->revoked_at,
            (string) $secondRevocation->revoked_at,
        );
    }

    public function test_revocation_is_token_specific(): void
    {
        $store = $this->app->make(
            TokenRevocationStoreInterface::class,
        );

        $revokedToken = 'revoked-token';

        $activeToken = 'different-active-token';

        $store->revoke(
            token: $revokedToken,
            expiresAt: now()->timestamp + 3600,
        );

        $this->assertTrue(
            $store->isRevoked(
                $revokedToken,
            ),
        );

        $this->assertFalse(
            $store->isRevoked(
                $activeToken,
            ),
        );
    }

    public function test_expired_token_is_not_persisted_as_revocation(): void
    {
        $store = $this->app->make(
            TokenRevocationStoreInterface::class,
        );

        $token = 'already-expired-token';

        $store->revoke(
            token: $token,
            expiresAt: now()->timestamp - 1,
        );

        $this->assertFalse(
            $store->isRevoked(
                $token,
            ),
        );

        $this->assertDatabaseMissing(
            'auth_token_revocations',
            [
                'token_hash' => hash(
                    'sha256',
                    $token,
                ),
            ],
        );
    }

    public function test_revocation_store_contract_resolves_database_implementation(): void
    {
        $store = $this->app->make(
            TokenRevocationStoreInterface::class,
        );

        $this->assertInstanceOf(
            DatabaseTokenRevocationStore::class,
            $store,
        );
    }
}
