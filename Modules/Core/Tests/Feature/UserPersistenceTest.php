<?php

declare(strict_types=1);

namespace Modules\Core\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Core\Identity\Models\User;
use Modules\Core\Person\Models\PersonModel;
use Modules\Core\Support\Uuid\UuidV7;
use Tests\TestCase;

final class UserPersistenceTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_factory_creates_canonical_person_and_uuid_v7_account(): void
    {
        $user = User::factory()->create();

        $this->assertTrue(
            UuidV7::validate((string) $user->getKey()),
        );

        $this->assertTrue(
            UuidV7::validate((string) $user->person_id),
        );

        $this->assertInstanceOf(
            PersonModel::class,
            $user->person,
        );

        $this->assertSame(
            (string) $user->person_id,
            (string) $user->person?->getKey(),
        );

        $this->assertNotSame(
            '',
            trim((string) $user->person?->getAttribute('name')),
        );
    }

    public function test_user_can_be_created_for_existing_person_without_duplicating_human_identity(): void
    {
        $person = PersonModel::factory()->create([
            'name' => 'Canonical Existing Person',
        ]);

        $user = User::factory()
            ->for($person, 'person')
            ->create([
                'email' => 'existing-person@educore.test',
            ]);

        $this->assertSame(
            (string) $person->getKey(),
            (string) $user->person_id,
        );

        $this->assertSame(
            'Canonical Existing Person',
            (string) $user->person?->getAttribute('name'),
        );

        $this->assertDatabaseCount('persons', 1);
        $this->assertDatabaseCount('users', 1);
    }
}
