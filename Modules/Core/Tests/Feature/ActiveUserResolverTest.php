<?php

declare(strict_types=1);

namespace Modules\Core\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Modules\Core\Identity\Contracts\ActiveUserResolverInterface;
use Modules\Core\Identity\Models\User;
use Modules\Core\Support\Uuid\UuidV7;
use Tests\TestCase;

final class ActiveUserResolverTest extends TestCase
{
    use RefreshDatabase;

    private ActiveUserResolverInterface $resolver;

    protected function setUp(): void
    {
        parent::setUp();

        $this->resolver = $this->app->make(
            ActiveUserResolverInterface::class,
        );
    }

    public function test_resolver_returns_active_canonical_user(): void
    {
        $userId = $this->createUser(
            status: 'ACTIVE',
        );

        $user = $this->resolver->findActiveById(
            $userId,
        );

        $this->assertInstanceOf(
            User::class,
            $user,
        );

        $this->assertSame(
            $userId,
            $user?->getKey(),
        );

        $this->assertSame(
            'ACTIVE',
            $user?->getAttribute('status'),
        );
    }

    public function test_resolver_rejects_inactive_user(): void
    {
        $userId = $this->createUser(
            status: 'SUSPENDED',
        );

        $user = $this->resolver->findActiveById(
            $userId,
        );

        $this->assertNull(
            $user,
        );
    }

    public function test_resolver_returns_null_for_missing_user(): void
    {
        $user = $this->resolver->findActiveById(
            UuidV7::generate(),
        );

        $this->assertNull(
            $user,
        );
    }

    public function test_resolver_rejects_malformed_identifier(): void
    {
        $user = $this->resolver->findActiveById(
            'not-a-uuid',
        );

        $this->assertNull(
            $user,
        );
    }

    public function test_resolver_rejects_empty_identifier(): void
    {
        $user = $this->resolver->findActiveById(
            '   ',
        );

        $this->assertNull(
            $user,
        );
    }

    private function createUser(
        string $status,
    ): string {
        $userId = UuidV7::generate();

        DB::table('users')->insert([
            'id' => $userId,
            'name' => sprintf(
                'Identity Resolver User %s',
                substr($userId, 0, 8),
            ),
            'email' => sprintf(
                'identity-%s@educore.test',
                str_replace('-', '', $userId),
            ),
            'password' => bcrypt('secret123'),
            'status' => $status,
            'is_superadmin' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $userId;
    }
}
