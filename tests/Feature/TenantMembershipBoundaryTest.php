<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Modules\Core\Identity\Models\User;
use Modules\Core\Tenancy\Contracts\TenantContextInterface;
use Symfony\Component\HttpFoundation\Response;
use Tests\Feature\Middleware\InjectTestTenantContext;
use Tests\TestCase;

final class TenantMembershipBoundaryTest extends TestCase
{
    use RefreshDatabase;

    private string $userId;
    private string $tenantId;
    private string $membershipId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->userId = Str::uuid()->toString();
        $this->tenantId = Str::uuid()->toString();
        $this->membershipId = Str::uuid()->toString();

        $this->registerTenantAwareTestRoute();

        $this->createUser();
        $this->createTenant();
    }

    protected function tearDown(): void
    {
        app(TenantContextInterface::class)->clear();

        parent::tearDown();
    }

    public function test_tenant_aware_route_allows_user_with_active_membership(): void
    {
        $this->createMembership(
            membershipId: $this->membershipId,
            status: 'ACTIVE',
        );

        $user = User::query()->findOrFail(
            $this->userId,
        );

        $response = $this
            ->actingAs($user)
            ->withHeader(
                'X-Tenant-ID',
                $this->tenantId,
            )
            ->getJson(
                '/test-tenant/membership-boundary',
            );

        $response
            ->assertStatus(Response::HTTP_OK)
            ->assertJson([
                'status' => 'success',
                'message' => 'Tenant membership boundary passed.',
            ]);
    }

    public function test_tenant_aware_route_rejects_user_without_membership(): void
    {
        $user = User::query()->findOrFail(
            $this->userId,
        );

        $response = $this
            ->actingAs($user)
            ->withHeader(
                'X-Tenant-ID',
                $this->tenantId,
            )
            ->getJson(
                '/test-tenant/membership-boundary',
            );

        $response
            ->assertStatus(Response::HTTP_FORBIDDEN)
            ->assertJson([
                'status' => 'error',
                'message' => 'Unauthorized: Your role does not possess the required clearance level for this tenant domain.',
            ]);
    }

    public function test_tenant_aware_route_rejects_suspended_membership(): void
    {
        $this->createMembership(
            membershipId: $this->membershipId,
            status: 'SUSPENDED',
        );

        $user = User::query()->findOrFail(
            $this->userId,
        );

        $response = $this
            ->actingAs($user)
            ->withHeader(
                'X-Tenant-ID',
                $this->tenantId,
            )
            ->getJson(
                '/test-tenant/membership-boundary',
            );

        $response
            ->assertStatus(Response::HTTP_FORBIDDEN)
            ->assertJson([
                'status' => 'error',
                'message' => 'Unauthorized: Your role does not possess the required clearance level for this tenant domain.',
            ]);
    }

    private function registerTenantAwareTestRoute(): void
    {
        Route::middleware([
            InjectTestTenantContext::class,
        ])->get(
            '/test-tenant/membership-boundary',
            static function () {
                return response()->json([
                    'status' => 'success',
                    'message' => 'Tenant membership boundary passed.',
                ], Response::HTTP_OK);
            },
        )->name(
            'test.tenant.membership-boundary',
        );
    }

    private function createUser(): void
    {
        DB::table('users')->insert([
            'id' => $this->userId,
            'name' => 'Tenant Boundary User',
            'email' => sprintf(
                'tenant-boundary-%s@educore.test',
                Str::lower(Str::random(10)),
            ),
            'password' => bcrypt('secret123'),
            'status' => 'ACTIVE',
            'is_superadmin' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function createTenant(): void
    {
        DB::table('tenants')->insert([
            'id' => $this->tenantId,
            'name' => 'Tenant Membership Boundary',
            'subdomain' => sprintf(
                'membership-boundary-%s',
                Str::lower(Str::random(8)),
            ),
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function createMembership(
        string $membershipId,
        string $status,
    ): void {
        DB::table('memberships')->insert([
            'id' => $membershipId,
            'user_id' => $this->userId,
            'tenant_id' => $this->tenantId,

            /*
             * Field role masih diperlukan oleh skema database lama,
             * tetapi tidak digunakan sebagai authorization source.
             */
            'role' => 'employee',

            'status' => $status,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
