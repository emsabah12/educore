<?php

declare(strict_types=1);

namespace Tests\Feature;

use Database\Seeders\GlobalSuperadminSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Modules\Core\Identity\Models\User;
use Tests\TestCase;

final class SuperadminAccessTest extends TestCase
{
    use RefreshDatabase;

    public function test_superadmin_seeder_creates_canonical_global_identity(): void
    {
        $this->seed(GlobalSuperadminSeeder::class);

        $user = User::query()
            ->with('person')
            ->where('email', 'bsaeful12@gmail.com')
            ->first();

        $this->assertNotNull(
            $user,
            'Akun global superadmin harus terdaftar.',
        );
        $this->assertTrue(
            (bool) $user->is_superadmin,
            'Flag is_superadmin wajib bernilai true.',
        );
        $this->assertSame('ACTIVE', $user->status);
        $this->assertNotNull(
            $user->person,
            'Global superadmin harus terhubung ke canonical Person.',
        );
        $this->assertSame(
            'EduCore Platform Owner',
            $user->person->name,
        );
        $this->assertSame(
            (string) $user->person->getKey(),
            (string) $user->person_id,
        );
    }

    public function test_superadmin_can_authenticate_with_canonical_user_model(): void
    {
        $this->seed(GlobalSuperadminSeeder::class);

        $superadmin = User::query()
            ->where('email', 'bsaeful12@gmail.com')
            ->firstOrFail();

        $this->assertTrue((bool) $superadmin->is_superadmin);

        $this->actingAs($superadmin);

        $this->assertAuthenticated();
        $this->assertSame(
            (string) $superadmin->getKey(),
            (string) Auth::id(),
        );
    }
}
