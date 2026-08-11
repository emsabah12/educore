<?php

declare(strict_types=1);

namespace Modules\Core\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Core\Identity\Contracts\ActiveUserResolverInterface;
use Modules\Core\Identity\Models\User;
use Modules\Core\Person\Models\PersonModel;
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

    public function test_resolver_returns_active_canonical_user_with_person_identity(): void
    {
        $user = $this->createUser(
            status: 'ACTIVE',
            personName: 'Active Canonical Person',
        );

        $resolved = $this->resolver->findActiveById(
            (string) $user->getKey(),
        );

        $this->assertInstanceOf(
            User::class,
            $resolved,
        );

        $this->assertSame(
            (string) $user->getKey(),
            (string) $resolved?->getKey(),
        );

        $this->assertSame(
            'ACTIVE',
            $resolved?->getAttribute('status'),
        );

        $this->assertTrue(
            $resolved?->relationLoaded('person') ?? false,
        );

        $this->assertSame(
            'Active Canonical Person',
            (string) $resolved?->person?->getAttribute('name'),
        );
    }

    public function test_resolver_rejects_inactive_user(): void
    {
        $user = $this->createUser(
            status: 'SUSPENDED',
            personName: 'Suspended Canonical Person',
        );

        $resolved = $this->resolver->findActiveById(
            (string) $user->getKey(),
        );

        $this->assertNull($resolved);
    }

    public function test_resolver_returns_null_for_missing_user(): void
    {
        $this->assertNull(
            $this->resolver->findActiveById(
                UuidV7::generate(),
            ),
        );
    }

    public function test_resolver_rejects_non_uuid_v7_identifier(): void
    {
        $this->assertNull(
            $this->resolver->findActiveById(
                '550e8400-e29b-41d4-a716-446655440000',
            ),
        );
    }

    public function test_resolver_rejects_empty_identifier(): void
    {
        $this->assertNull(
            $this->resolver->findActiveById('   '),
        );
    }

    private function createUser(
        string $status,
        string $personName,
    ): User {
        $person = PersonModel::factory()->create([
            'name' => $personName,
        ]);

        $user = User::factory()
            ->for($person, 'person')
            ->create();

        $user->forceFill([
            'status' => $status,
        ])->save();

        return $user->fresh('person') ?? $user;
    }
}
