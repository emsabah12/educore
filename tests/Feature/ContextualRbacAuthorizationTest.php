<?php

declare(strict_types=1);

namespace Tests\Feature;

use Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use App\Models\User;

final class ContextualRbacAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Mengatur rute tiruan (mocking routes) untuk mensimulasikan endpoint riil yang diproteksi.
     */
    protected function setUp(): void
    {
        parent::setUp();

        // 1. Jalankan migrasi modular untuk membangun seluruh skema tabel dasar & modular
        $this->artisan('migrate', [
            '--path' => [
                'database/migrations',
                'Modules/Core/Database/Migrations',
                'Modules/Auth/Database/Migrations',
                'Modules/User/Database/Migrations',
            ],
            '--realpath' => true
        ]);

        // 2. Daftarkan rute testing tiruan yang dilindungi oleh middleware tenant.role
        Route::middleware(['web', 'tenant.role:admin'])->group(function () {
            Route::get('/test-tenant/dashboard', function () {
                return response()->json(['status' => 'success', 'data' => 'Welcome to Tenant Dashboard']);
            })->name('test.tenant.dashboard');
        });
    }

    /**
     * Memastikan user yang memiliki role admin pada membership yang bersangkutan diizinkan masuk.
     */
    public function test_user_with_admin_role_in_current_membership_is_allowed_access(): void
    {
        $userId = '111aa11f-4c99-4484-8249-cfcce8c45651';
        $tenantId = '222aa22f-4c99-4484-8249-cfcce8c45652';
        $membershipId = '333aa33f-4c99-4484-8249-cfcce8c45653';
        $roleId = '444aa44f-4c99-4484-8249-cfcce8c45654';

        DB::table('users')->insert([
            'id' => $userId,
            'name' => 'Saeful Admin',
            'email' => 'admin@educore.test',
            'password' => 'secret',
            'is_superadmin' => false,
            'created_at' => now(),
            'updated_at' => now()
        ]);

        // Mematuhi NOT NULL constraint kolom subdomain
        DB::table('tenants')->insert([
            'id' => $tenantId,
            'name' => 'Sekolah Menengah A',
            'subdomain' => 'sma-a',
            'created_at' => now(),
            'updated_at' => now()
        ]);

        // Mematuhi skema asli memberships Anda dengan menyertakan role makro dan status
        DB::table('memberships')->insert([
            'id' => $membershipId,
            'user_id' => $userId,
            'tenant_id' => $tenantId,
            'role' => 'PEGAWAI',
            'status' => 'ACTIVE',
            'created_at' => now(),
            'updated_at' => now()
        ]);

        DB::table('roles')->insert([
            'id' => $roleId,
            'name' => 'admin',
            'display_name' => 'Admin Sekolah',
            'created_at' => now(),
            'updated_at' => now()
        ]);

        // Menghubungkan hak akses granular mikro (admin) ke dalam keanggotaan institusi
        DB::table('membership_roles')->insert([
            'membership_id' => $membershipId,
            'role_id' => $roleId
        ]);

        // Act
        $userModel = User::find($userId);

        $response = $this->actingAs($userModel)
            ->withHeaders(['X-Membership-ID' => $membershipId])
            ->json('GET', '/test-tenant/dashboard');

        // Assert
        $response->assertStatus(200);
        $response->assertJsonPath('status', 'success');
    }

    /**
     * Memastikan user ditolak jika mencoba mengakses data menggunakan ID Membership institusi lain.
     */
    public function test_user_is_forbidden_when_accessing_with_unauthorized_membership_context(): void
    {
        $userId = '111aa11f-4c99-4484-8249-cfcce8c45651';
        $tenantAId = '222aa22f-4c99-4484-8249-cfcce8c45652';
        $membershipAId = '333aa33f-4c99-4484-8249-cfcce8c45653';
        $roleId = '444aa44f-4c99-4484-8249-cfcce8c45654';

        // Konteks membership ilegal
        $membershipBId = '999bb99f-4c99-4484-8249-cfcce8c45699';

        DB::table('users')->insert([
            'id' => $userId,
            'name' => 'Saeful Admin',
            'email' => 'admin@educore.test',
            'password' => 'secret',
            'is_superadmin' => false,
            'created_at' => now(),
            'updated_at' => now()
        ]);

        DB::table('tenants')->insert([
            'id' => $tenantAId,
            'name' => 'Sekolah Menengah A',
            'subdomain' => 'sma-a',
            'created_at' => now(),
            'updated_at' => now()
        ]);

        DB::table('memberships')->insert([
            'id' => $membershipAId,
            'user_id' => $userId,
            'tenant_id' => $tenantAId,
            'role' => 'PEGAWAI',
            'status' => 'ACTIVE',
            'created_at' => now(),
            'updated_at' => now()
        ]);

        DB::table('roles')->insert([
            'id' => $roleId,
            'name' => 'admin',
            'display_name' => 'Admin Sekolah',
            'created_at' => now(),
            'updated_at' => now()
        ]);

        DB::table('membership_roles')->insert([
            'membership_id' => $membershipAId,
            'role_id' => $roleId
        ]);

        // Act
        $userModel = User::find($userId);

        $response = $this->actingAs($userModel)
            ->withHeaders(['X-Membership-ID' => $membershipBId])
            ->json('GET', '/test-tenant/dashboard');

        // Assert
        $response->assertStatus(403);
        $response->assertJsonPath('message', 'Unauthorized: Your role does not possess the required clearance level for this tenant domain.');
    }
}
