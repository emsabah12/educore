<?php

declare(strict_types=1);

namespace Tests\Feature;

use Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use App\Models\User;
use Modules\User\Http\Controllers\Api\v1\MembershipResolutionController;

final class MembershipContextSwitchTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Mengatur lingkungan database testing modular dan bootstrapping route sebelum pengujian.
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->artisan('migrate', [
            '--path' => [
                'database/migrations',
                'Modules/Core/Database/Migrations',
                'Modules/Auth/Database/Migrations',
                'Modules/User/Database/Migrations',
            ],
            '--realpath' => true
        ]);

        Route::middleware(['api', \Illuminate\Session\Middleware\StartSession::class])->group(function () {
            Route::post('/api/v1/user/memberships/{membership_id}/switch', [MembershipResolutionController::class, 'switchContext'])
                ->name('test.api.user.memberships.switch');
        });
    }

    /**
     * Memastikan user berhasil beralih konteks ke membership miliknya yang aktif.
     */
    public function test_user_can_switch_to_their_own_active_membership(): void
    {
        $this->withoutExceptionHandling();

        // Menggunakan standard format UUID murni 100% yang valid untuk PostgreSQL
        $userId = '777aa77f-4c99-4484-8249-cfcce8c45651';
        $tenantId = '888aa88f-4c99-4484-8249-cfcce8c45652';
        $membershipId = '999aa99f-4c99-4484-8249-cfcce8c45653';

        DB::table('users')->insert([
            'id' => $userId,
            'name' => 'Saeful Mentor',
            'email' => 'saeful@educore.test',
            'password' => 'secret',
            'is_superadmin' => false,
            'created_at' => now(),
            'updated_at' => now()
        ]);

        DB::table('tenants')->insert([
            'id' => $tenantId,
            'name' => 'Lubna Sticky Milk Academy',
            'subdomain' => 'lubna-edu',
            'created_at' => now(),
            'updated_at' => now()
        ]);

        DB::table('memberships')->insert([
            'id' => $membershipId,
            'user_id' => $userId,
            'tenant_id' => $tenantId,
            'role' => 'PEGAWAI',
            'status' => 'ACTIVE',
            'created_at' => now(),
            'updated_at' => now()
        ]);

        $userModel = User::find($userId);

        // Act
        $response = $this->actingAs($userModel)
            ->json('POST', "/api/v1/user/memberships/{$membershipId}/switch");

        // Assert
        $response->assertStatus(200);
        $response->assertJsonPath('status', 'success');

        $this->assertEquals($membershipId, session('active_membership_id'));
        $this->assertEquals($tenantId, session('active_tenant_id'));
    }

    /**
     * Memastikan user ditolak (403) jika mencoba menembus membership milik user lain.
     */
    public function test_user_is_forbidden_from_switching_to_another_users_membership(): void
    {
        // Pastikan ID User A dan User B berbentuk UUID lengkap dan valid!
        $userAId = '111aa11f-4c99-4484-8249-cfcce8c45651';
        $userBId = '222bb22f-4c99-4484-8249-cfcce8c45652';
        $tenantId = '888aa88f-4c99-4484-8249-cfcce8c45653';
        $membershipBId = '999bb99f-4c99-4484-8249-cfcce8c45654';

        DB::table('users')->insert([
            ['id' => $userAId, 'name' => 'User A', 'email' => 'usera@educore.test', 'password' => 'secret', 'is_superadmin' => false, 'created_at' => now(), 'updated_at' => now()],
            ['id' => $userBId, 'name' => 'User B', 'email' => 'userb@educore.test', 'password' => 'secret', 'is_superadmin' => false, 'created_at' => now(), 'updated_at' => now()]
        ]);

        DB::table('tenants')->insert([
            'id' => $tenantId,
            'name' => 'Sekolah B',
            'subdomain' => 'sekolah-b',
            'created_at' => now(),
            'updated_at' => now()
        ]);

        DB::table('memberships')->insert([
            'id' => $membershipBId,
            'user_id' => $userBId,
            'tenant_id' => $tenantId,
            'role' => 'PEGAWAI',
            'status' => 'ACTIVE',
            'created_at' => now(),
            'updated_at' => now()
        ]);

        $userAModel = User::find($userAId);

        // Act
        $response = $this->actingAs($userAModel)
            ->json('POST', "/api/v1/user/memberships/{$membershipBId}/switch");

        // Assert
        $response->assertStatus(403);
        $response->assertJsonPath('message', 'Akses ditolak: Anda tidak terdaftar atau tidak aktif pada lembaga ini.');
    }
}
