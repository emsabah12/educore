<?php

declare(strict_types=1);

namespace Modules\Core\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Modules\Auth\Token\Contracts\TokenManagerInterface;
use Modules\Core\Governance\Audit\Contracts\AuditTrailServiceInterface;
use Modules\Core\Support\Uuid\UuidV7;
use Tests\TestCase;

final class TenantManagementTest extends TestCase
{
    use RefreshDatabase;

    private const TENANTS_ENDPOINT = '/api/v1/core/tenants';

    private string $superadminId;
    private string $pegawaiId;
    private string $tenantId;

    private string $superadminMembershipId;
    private string $pegawaiMembershipId;

    private TokenManagerInterface $tokenManager;

    protected function setUp(): void
    {
        parent::setUp();

        /*
         * Audit persistence bukan fokus test ini.
         *
         * Test tetap menguji route, authentication middleware,
         * global authorization middleware, controller, repository,
         * dan database tenant.
         */
        $this->app->instance(
            AuditTrailServiceInterface::class,
            $this->createStub(
                AuditTrailServiceInterface::class,
            ),
        );

        $this->tokenManager = $this->app->make(
            TokenManagerInterface::class,
        );

        $this->superadminId = UuidV7::generate();
        $this->pegawaiId = UuidV7::generate();
        $this->tenantId = UuidV7::generate();

        $this->superadminMembershipId = UuidV7::generate();
        $this->pegawaiMembershipId = UuidV7::generate();

        $this->createFixtures();
    }

    public function test_global_superadmin_can_create_new_tenant(): void
    {
        $payload = [
            'name' => 'SMP IT Inovasi Bangsa',
            'subdomain' => 'smp-inovasi',
        ];

        $response = $this
            ->withToken(
                $this->issueToken(
                    $this->superadminId,
                    $this->superadminMembershipId,
                ),
            )
            ->postJson(
                self::TENANTS_ENDPOINT,
                $payload,
            );

        $response
            ->assertCreated()
            ->assertJsonPath('status', 'success')
            ->assertJsonPath(
                'message',
                'Tenant registered successfully.',
            )
            ->assertJsonPath(
                'data.name',
                $payload['name'],
            )
            ->assertJsonPath(
                'data.subdomain',
                $payload['subdomain'],
            );

        $this->assertDatabaseHas('tenants', [
            'name' => $payload['name'],
            'subdomain' => $payload['subdomain'],
            'is_active' => true,
        ]);
    }

    public function test_non_superadmin_is_forbidden_to_create_tenant(): void
    {
        $payload = [
            'name' => 'Lembaga Penerobos',
            'subdomain' => 'terobos',
        ];

        $response = $this
            ->withToken(
                $this->issueToken(
                    $this->pegawaiId,
                    $this->pegawaiMembershipId,
                ),
            )
            ->postJson(
                self::TENANTS_ENDPOINT,
                $payload,
            );

        $response
            ->assertForbidden()
            ->assertExactJson([
                'status' => 'error',
                'message' => 'Forbidden. This action requires global superadmin privileges.',
            ]);

        $this->assertDatabaseMissing('tenants', [
            'subdomain' => $payload['subdomain'],
        ]);
    }

    public function test_tenant_management_requires_authenticated_identity(): void
    {
        $this
            ->postJson(
                self::TENANTS_ENDPOINT,
                [
                    'name' => 'Tenant Tanpa Authentication',
                    'subdomain' => 'tanpa-auth',
                ],
            )
            ->assertUnauthorized()
            ->assertExactJson([
                'status' => 'error',
                'message' => 'Unauthenticated. Invalid or missing identity context.',
            ]);

        $this->assertDatabaseMissing('tenants', [
            'subdomain' => 'tanpa-auth',
        ]);
    }

    private function createFixtures(): void
    {
        DB::table('tenants')->insert([
            'id' => $this->tenantId,
            'name' => 'Sekolah Pusat EduCore',
            'subdomain' => 'pusat',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('users')->insert([
            'id' => $this->superadminId,
            'name' => 'Superadmin Global',
            'email' => 'super@educore.test',
            'password' => Hash::make('secret123'),
            'status' => 'ACTIVE',
            'is_superadmin' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        /*
         * Legacy role sengaja bukan SUPERADMIN.
         *
         * Test ini membuktikan global authorization berasal dari
         * users.is_superadmin, bukan memberships.role.
         */
        DB::table('memberships')->insert([
            'id' => $this->superadminMembershipId,
            'user_id' => $this->superadminId,
            'tenant_id' => $this->tenantId,
            'role' => 'PEGAWAI',
            'status' => 'ACTIVE',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('users')->insert([
            'id' => $this->pegawaiId,
            'name' => 'Pegawai Administrasi',
            'email' => 'staff@educore.test',
            'password' => Hash::make('secret123'),
            'status' => 'ACTIVE',
            'is_superadmin' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        /*
         * Legacy role sengaja SUPERADMIN.
         *
         * Walaupun field lama memiliki nilai SUPERADMIN, user tetap
         * harus ditolak karena users.is_superadmin bernilai false.
         */
        DB::table('memberships')->insert([
            'id' => $this->pegawaiMembershipId,
            'user_id' => $this->pegawaiId,
            'tenant_id' => $this->tenantId,
            'role' => 'SUPERADMIN',
            'status' => 'ACTIVE',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function issueToken(
        string $userId,
        string $membershipId,
    ): string {
        return $this->tokenManager->issueToken(
            $userId,
            $this->tenantId,
            [
                'membership_id' => $membershipId,
            ],
        );
    }

    public function test_global_superadmin_does_not_require_active_tenant_context(): void
    {
        $unresolvedTenantId = UuidV7::generate();

        $payload = [
            'name' => 'Tenant Global Tanpa Context',
            'subdomain' => 'global-tanpa-context',
        ];

        /*
     * Token sengaja menunjuk ke tenant yang tidak ada.
     *
     * Global tenant management hanya membutuhkan canonical identity
     * dan users.is_superadmin. Tenant context tidak boleh di-resolve.
     */
        $token = $this->tokenManager->issueToken(
            $this->superadminId,
            $unresolvedTenantId,
            [
                'membership_id' => $this->superadminMembershipId,
            ],
        );

        $response = $this
            ->withToken($token)
            ->postJson(
                self::TENANTS_ENDPOINT,
                $payload,
            );

        $response
            ->assertCreated()
            ->assertJsonPath('status', 'success')
            ->assertJsonPath(
                'data.subdomain',
                $payload['subdomain'],
            );

        $this->assertDatabaseHas('tenants', [
            'name' => $payload['name'],
            'subdomain' => $payload['subdomain'],
            'is_active' => true,
        ]);
    }
}
