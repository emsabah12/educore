<?php

declare(strict_types=1);

namespace Modules\Core\Tests\Feature;

use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Modules\Core\Support\Uuid\UuidV7;
use Tests\TestCase;

final class UserUsernamePersistenceTest extends TestCase
{
    use RefreshDatabase;

    public function test_users_table_exposes_global_username_column(): void
    {
        $this->assertTrue(
            Schema::hasColumn(
                'users',
                'username',
            ),
            'Canonical users table must expose the global username column.',
        );
    }

    public function test_multiple_users_may_have_null_username(): void
    {
        $this->assertUsernameColumnExists();

        $firstPersonId = UuidV7::generate();
        $secondPersonId = UuidV7::generate();

        $firstUserId = UuidV7::generate();
        $secondUserId = UuidV7::generate();

        $this->insertPerson(
            personId: $firstPersonId,
            name: 'Username Null User One',
        );

        $this->insertPerson(
            personId: $secondPersonId,
            name: 'Username Null User Two',
        );

        $this->insertUser(
            userId: $firstUserId,
            personId: $firstPersonId,
            email: $this->uniqueEmail('username-null-one'),
            username: null,
        );

        $this->insertUser(
            userId: $secondUserId,
            personId: $secondPersonId,
            email: $this->uniqueEmail('username-null-two'),
            username: null,
        );

        $this->assertSame(
            2,
            DB::table('users')
                ->whereNull('username')
                ->whereIn(
                    'id',
                    [
                        $firstUserId,
                        $secondUserId,
                    ],
                )
                ->count(),
        );
    }

    public function test_existing_style_user_insert_without_username_remains_compatible(): void
    {
        $this->assertUsernameColumnExists();

        $personId = UuidV7::generate();
        $userId = UuidV7::generate();

        $this->insertPerson(
            personId: $personId,
            name: 'Legacy Compatible User',
        );

        $this->insertUserWithoutUsername(
            userId: $userId,
            personId: $personId,
            email: $this->uniqueEmail('legacy-compatible'),
        );

        $this->assertNull(
            DB::table('users')
                ->where('id', $userId)
                ->value('username'),
            'Existing User write paths that omit username must remain compatible.',
        );
    }

    public function test_non_null_username_is_globally_unique(): void
    {
        $this->assertUsernameColumnExists();

        $firstPersonId = UuidV7::generate();
        $secondPersonId = UuidV7::generate();

        $this->insertPerson(
            personId: $firstPersonId,
            name: 'Unique Username User One',
        );

        $this->insertPerson(
            personId: $secondPersonId,
            name: 'Unique Username User Two',
        );

        $this->insertUser(
            userId: UuidV7::generate(),
            personId: $firstPersonId,
            email: $this->uniqueEmail('unique-username-one'),
            username: 'global-admin',
        );

        $this->expectException(
            QueryException::class,
        );

        $this->insertUser(
            userId: UuidV7::generate(),
            personId: $secondPersonId,
            email: $this->uniqueEmail('unique-username-two'),
            username: 'global-admin',
        );
    }

    private function assertUsernameColumnExists(): void
    {
        $this->assertTrue(
            Schema::hasColumn(
                'users',
                'username',
            ),
            'Canonical users table must expose username before username persistence behavior can be tested.',
        );
    }

    private function insertPerson(
        string $personId,
        string $name,
    ): void {
        DB::table('persons')->insert([
            'id' => $personId,
            'name' => $name,
            'status' => 'ACTIVE',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function insertUser(
        string $userId,
        string $personId,
        string $email,
        ?string $username,
    ): void {
        DB::table('users')->insert([
            'id' => $userId,
            'person_id' => $personId,
            'email' => $email,
            'username' => $username,
            'password' => bcrypt('secret123'),
            'status' => 'ACTIVE',
            'is_superadmin' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function insertUserWithoutUsername(
        string $userId,
        string $personId,
        string $email,
    ): void {
        DB::table('users')->insert([
            'id' => $userId,
            'person_id' => $personId,
            'email' => $email,
            'password' => bcrypt('secret123'),
            'status' => 'ACTIVE',
            'is_superadmin' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function uniqueEmail(string $prefix): string
    {
        return sprintf(
            '%s-%s@educore.test',
            $prefix,
            Str::lower(
                Str::random(10),
            ),
        );
    }
}
