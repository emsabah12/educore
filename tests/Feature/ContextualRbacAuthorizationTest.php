<?php

declare(strict_types=1);

namespace Tests\Feature;

use Closure;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Modules\Core\Identity\Models\User;
use Tests\Feature\Middleware\InjectTestTenantContext;
use Tests\TestCase;

final class ContextualRbacAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Mengatur rute tiruan untuk mensimulasikan endpoint
     * yang diproteksi oleh contextual tenant RBAC.
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
                'Modules/Academic/Database/Migrations',
            ],
            '--realpath' => true,
        ]);

        Route::middleware([
            'web',
            \Tests\Feature\Middleware\InjectTestTenantContext::class,
            'tenant.role:admin',
        ])->group(function (): void {
            Route::get(
                '/test-tenant/dashboard',
                function () {
                    return response()->json([
                        'status' => 'success',
                        'data' => 'Welcome to Tenant Dashboard',
                    ]);
                }
            )->name('test.tenant.dashboard');

            Route::get(
                '/test-tenant/dashboard/{membership_id}',
                function (string $membership_id) {
                    return response()->json([
                        'status' => 'success',
                        'data' => 'Welcome to Tenant Dashboard',
                        'membership_id' => $membership_id,
                    ]);
                }
            )->name('test.tenant.dashboard.membership');
        });
    }

    /**
     * Memastikan user yang memiliki role admin pada membership
     * yang bersangkutan diizinkan masuk.
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
            'updated_at' => now(),
        ]);


        DB::table('tenants')->insert([
            'id' => $tenantId,
            'name' => 'Sekolah Menengah A',
            'subdomain' => 'sma-a',
            'created_at' => now(),
            'updated_at' => now(),
        ]);


        DB::table('memberships')->insert([
            'id' => $membershipId,
            'user_id' => $userId,
            'tenant_id' => $tenantId,
            'role' => 'PEGAWAI',
            'status' => 'ACTIVE',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('roles')->insert([
            'id' => $roleId,
            'name' => 'admin',
            'display_name' => 'Admin Sekolah',
            'created_at' => now(),
            'updated_at' => now(),
        ]);


        DB::table('membership_roles')->insert([
            'membership_id' => $membershipId,
            'role_id' => $roleId,
        ]);

        $userModel = User::findOrFail($userId);

        $response = $this->actingAs($userModel)
            ->withHeaders([
                'X-Test-Authenticated-Membership-ID' =>
                $membershipId,
                'X-Tenant-ID' => $tenantId,
            ])
            ->json(
                'GET',
                '/test-tenant/dashboard'
            );

        $response->assertStatus(200);
        $response->assertJsonPath(
            'status',
            'success'
        );
    }

    /**
     * Memastikan user ditolak jika mencoba mengakses
     * menggunakan membership yang bukan miliknya.
     *
     * Security invariant:
     *
     * User A tidak boleh menggunakan membership milik User B,
     * walaupun membership tersebut memiliki role admin.
     */
    public function test_user_is_forbidden_when_accessing_with_unauthorized_membership_context(): void
    {
        $userId = '111aa11f-4c99-4484-8249-cfcce8c45651';
        $tenantAId = '222aa22f-4c99-4484-8249-cfcce8c45652';
        $membershipAId = '333aa33f-4c99-4484-8249-cfcce8c45653';
        $membershipBId = '999bb99f-4c99-4484-8249-cfcce8c45699';
        $roleId = '444aa44f-4c99-4484-8249-cfcce8c45654';



        DB::table('users')->insert([
            'id' => $userId,
            'name' => 'Saeful Admin',
            'email' => 'admin@educore.test',
            'password' => 'secret',
            'is_superadmin' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('tenants')->insert([
            'id' => $tenantAId,
            'name' => 'Sekolah Menengah A',
            'subdomain' => 'sma-a',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        /*
         * Membership valid milik authenticated user.
         */
        DB::table('memberships')->insert([
            'id' => $membershipAId,
            'user_id' => $userId,
            'tenant_id' => $tenantAId,
            'role' => 'PEGAWAI',
            'status' => 'ACTIVE',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('roles')->insert([
            'id' => $roleId,
            'name' => 'admin',
            'display_name' => 'Admin Sekolah',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('membership_roles')->insert([
            'membership_id' => $membershipAId,
            'role_id' => $roleId,
        ]);

        $userModel = User::findOrFail($userId);

        /*
        * membershipBId sengaja bukan membership
        * milik authenticated user.
        *
        * Test-only middleware mensimulasikan membership_id
        * yang berasal dari token tervalidasi.
        *
        * MembershipContextResolver wajib tetap menolak
        * ownership yang tidak cocok.
        */
        $response = $this->actingAs($userModel)
            ->withHeaders([
                'X-Test-Authenticated-Membership-ID' =>
                $membershipBId,
                'X-Tenant-ID' => $tenantAId,
            ])
            ->json(
                'GET',
                '/test-tenant/dashboard'
            );

        $response->assertStatus(403);
        $response->assertJsonPath(
            'message',
            'Unauthorized: Your role does not possess the required clearance level for this tenant domain.'
        );
    }

    public function test_user_cannot_use_membership_from_another_tenant_in_current_tenant_context(): void
    {
        $userId = '111aa11f-4c99-4484-8249-cfcce8c45651';

        $tenantAId = '222aa22f-4c99-4484-8249-cfcce8c45652';
        $tenantBId = '888bb88f-4c99-4484-8249-cfcce8c45688';

        $membershipAId = '333aa33f-4c99-4484-8249-cfcce8c45653';
        $membershipBId = '777bb77f-4c99-4484-8249-cfcce8c45677';

        $roleId = '444aa44f-4c99-4484-8249-cfcce8c45654';

        DB::table('users')->insert([
            'id' => $userId,
            'name' => 'Multi Tenant Admin',
            'email' => 'multi-tenant-admin@educore.test',
            'password' => 'secret',
            'is_superadmin' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('tenants')->insert([
            [
                'id' => $tenantAId,
                'name' => 'Sekolah Menengah A',
                'subdomain' => 'sma-a',
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'id' => $tenantBId,
                'name' => 'Sekolah Menengah B',
                'subdomain' => 'sma-b',
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);

        DB::table('memberships')->insert([
            [
                'id' => $membershipAId,
                'user_id' => $userId,
                'tenant_id' => $tenantAId,
                'role' => 'PEGAWAI',
                'status' => 'ACTIVE',
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'id' => $membershipBId,
                'user_id' => $userId,
                'tenant_id' => $tenantBId,
                'role' => 'PEGAWAI',
                'status' => 'ACTIVE',
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);

        DB::table('roles')->insert([
            'id' => $roleId,
            'name' => 'admin',
            'display_name' => 'Admin Sekolah',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('membership_roles')->insert([
            [
                'membership_id' => $membershipAId,
                'role_id' => $roleId,
            ],
            [
                'membership_id' => $membershipBId,
                'role_id' => $roleId,
            ],
        ]);

        $userModel = User::findOrFail($userId);

        $response = $this->actingAs($userModel)
            ->withHeaders([
                // Authentication context dipercaya berasal dari
                // middleware authentication.
                'X-Tenant-ID' => $tenantAId,

                // Attacker mencoba menggunakan membership
                // yang sebenarnya berada di Tenant B.
                'X-Test-Authenticated-Membership-ID' =>
                $membershipBId,
            ])
            ->json(
                'GET',
                '/test-tenant/dashboard'
            );

        $response->assertStatus(403);

        $response->assertJsonPath(
            'message',
            'Unauthorized: Your role does not possess the required clearance level for this tenant domain.'
        );
    }
    public function test_route_membership_cannot_override_authenticated_membership_context(): void
    {
        $userId = '111aa11f-4c99-4484-8249-cfcce8c45651';

        $tenantAId = '222aa22f-4c99-4484-8249-cfcce8c45652';
        $tenantBId = '888bb88f-4c99-4484-8249-cfcce8c45688';

        $membershipAId = '333aa33f-4c99-4484-8249-cfcce8c45653';
        $membershipBId = '999bb99f-4c99-4484-8249-cfcce8c45699';

        $roleId = '444aa44f-4c99-4484-8249-cfcce8c45654';

        DB::table('users')->insert([
            'id' => $userId,
            'name' => 'Saeful Admin',
            'email' => 'admin-route-context@educore.test',
            'password' => 'secret',
            'is_superadmin' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('tenants')->insert([
            [
                'id' => $tenantAId,
                'name' => 'Sekolah Menengah A',
                'subdomain' => 'sma-route-a',
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'id' => $tenantBId,
                'name' => 'Sekolah Menengah B',
                'subdomain' => 'sma-route-b',
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);

        DB::table('memberships')->insert([
            [
                'id' => $membershipAId,
                'user_id' => $userId,
                'tenant_id' => $tenantAId,
                'role' => 'PEGAWAI',
                'status' => 'ACTIVE',
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'id' => $membershipBId,
                'user_id' => $userId,
                'tenant_id' => $tenantBId,
                'role' => 'PEGAWAI',
                'status' => 'ACTIVE',
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);

        DB::table('roles')->insert([
            'id' => $roleId,
            'name' => 'admin',
            'display_name' => 'Admin Sekolah',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('membership_roles')->insert([
            'membership_id' => $membershipBId,
            'role_id' => $roleId,
        ]);

        $userModel = User::findOrFail($userId);

        $response = $this->actingAs($userModel)
            ->withHeaders([
                /*
         * Authenticated membership berasal dari Tenant A.
         */
                'X-Test-Authenticated-Membership-ID' =>
                $membershipAId,

                /*
         * Current tenant sengaja Tenant B.
         */
                'X-Tenant-ID' => $tenantBId,
            ])
            ->get(
                "/test-tenant/dashboard/{$membershipBId}"
            );

        $response->assertStatus(403);

        $response->assertJsonPath(
            'message',
            'Unauthorized: Your role does not possess the required clearance level for this tenant domain.',
        );
    }
}
