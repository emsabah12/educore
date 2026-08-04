<?php

declare(strict_types=1);

namespace Modules\User\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Core\Identity\Models\User;
use Modules\Auth\Token\Contracts\TokenManagerInterface;
use Tests\TestCase;

final class SwitchMembershipTest extends TestCase
{
    use RefreshDatabase;

    private string $userAId;
    private string $userBId;

    private string $tenantAId;
    private string $tenantBId;
    private string $inactiveTenantId;

    private string $membershipAId;
    private string $membershipBId;
    private string $inactiveMembershipId;
    private string $inactiveTenantMembershipId;
    private string $otherUserMembershipId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->userAId = Str::uuid()->toString();
        $this->userBId = Str::uuid()->toString();

        $this->tenantAId = Str::uuid()->toString();
        $this->tenantBId = Str::uuid()->toString();
        $this->inactiveTenantId = Str::uuid()->toString();

        $this->membershipAId = Str::uuid()->toString();
        $this->membershipBId = Str::uuid()->toString();
        $this->inactiveMembershipId = Str::uuid()->toString();
        $this->inactiveTenantMembershipId = Str::uuid()->toString();
        $this->otherUserMembershipId = Str::uuid()->toString();

        $this->createUsers();
        $this->createTenants();
        $this->createMemberships();
    }

    public function test_authenticated_user_can_select_owned_active_membership(): void
    {
        $token = app(TokenManagerInterface::class)
            ->issueToken(
                $this->userAId,
                $this->tenantAId,
            );


        $response = $this
            ->withToken($token)
            ->postJson(
                sprintf(
                    '/api/v1/user/memberships/%s/switch',
                    $this->membershipAId,
                ),
            );

        $response
            ->assertOk()
            ->assertJsonPath('status', 'success')
            ->assertJsonPath(
                'context.membership_id',
                $this->membershipAId,
            )
            ->assertJsonPath(
                'context.tenant_id',
                $this->tenantAId,
            )
            ->assertJsonPath(
                'context.tenant_name',
                'Switch Tenant A',
            );

        /*
         * Stateless invariant:
         * endpoint tidak menyimpan active context pada server session.
         */
        $this->assertNull(
            session('active_membership_id'),
        );

        $this->assertNull(
            session('active_tenant_id'),
        );
    }

    public function test_authenticated_user_can_select_between_owned_memberships(): void
    {
        $token = app(TokenManagerInterface::class)
            ->issueToken(
                $this->userAId,
                $this->tenantAId,
            );

        $firstResponse = $this
            ->withToken($token)
            ->postJson(
                sprintf(
                    '/api/v1/user/memberships/%s/switch',
                    $this->membershipAId,
                ),
            );

        $firstResponse
            ->assertOk()
            ->assertJsonPath(
                'context.membership_id',
                $this->membershipAId,
            )
            ->assertJsonPath(
                'context.tenant_id',
                $this->tenantAId,
            );

        $secondResponse = $this
            ->withToken($token)
            ->postJson(
                sprintf(
                    '/api/v1/user/memberships/%s/switch',
                    $this->membershipBId,
                ),
            );

        $secondResponse
            ->assertOk()
            ->assertJsonPath(
                'context.membership_id',
                $this->membershipBId,
            )
            ->assertJsonPath(
                'context.tenant_id',
                $this->tenantBId,
            );

        $this->assertNull(
            session('active_membership_id'),
        );

        $this->assertNull(
            session('active_tenant_id'),
        );
    }

    public function test_user_cannot_select_another_users_membership(): void
    {
        $token = app(TokenManagerInterface::class)
            ->issueToken(
                $this->userAId,
                $this->tenantAId,
            );

        $response = $this
            ->withToken($token)
            ->postJson(
                sprintf(
                    '/api/v1/user/memberships/%s/switch',
                    $this->otherUserMembershipId,
                ),
            );

        $response
            ->assertForbidden()
            ->assertJsonPath('status', 'error');

        $this->assertNull(
            session('active_membership_id'),
        );

        $this->assertNull(
            session('active_tenant_id'),
        );
    }

    public function test_user_cannot_select_inactive_membership(): void
    {
        $token = app(TokenManagerInterface::class)
            ->issueToken(
                $this->userAId,
                $this->tenantAId,
            );


        $response = $this
            ->withToken($token)
            ->postJson(
                sprintf(
                    '/api/v1/user/memberships/%s/switch',
                    $this->inactiveMembershipId,
                ),
            );

        $response
            ->assertForbidden()
            ->assertJsonPath('status', 'error');

        $this->assertNull(
            session('active_membership_id'),
        );

        $this->assertNull(
            session('active_tenant_id'),
        );
    }

    public function test_user_cannot_select_membership_of_inactive_tenant(): void
    {
        $token = app(TokenManagerInterface::class)
            ->issueToken(
                $this->userAId,
                $this->tenantAId,
            );

        $response = $this
            ->withToken($token)
            ->postJson(
                sprintf(
                    '/api/v1/user/memberships/%s/switch',
                    $this->inactiveTenantMembershipId,
                ),
            );

        $response
            ->assertForbidden()
            ->assertJsonPath('status', 'error');

        $this->assertNull(
            session('active_membership_id'),
        );

        $this->assertNull(
            session('active_tenant_id'),
        );
    }

    public function test_unauthenticated_user_cannot_select_membership(): void
    {
        $this
            ->postJson(
                sprintf(
                    '/api/v1/user/memberships/%s/switch',
                    $this->membershipAId,
                ),
            )
            ->assertUnauthorized();
    }

    private function createUsers(): void
    {
        DB::table('users')->insert([
            $this->userData(
                $this->userAId,
                'Switch User A',
                'switch-user-a',
            ),
            $this->userData(
                $this->userBId,
                'Switch User B',
                'switch-user-b',
            ),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function userData(
        string $id,
        string $name,
        string $emailPrefix,
    ): array {
        return [
            'id' => $id,
            'name' => $name,
            'email' => sprintf(
                '%s-%s@educore.test',
                $emailPrefix,
                Str::lower(Str::random(8)),
            ),
            'password' => bcrypt('secret123'),
            'status' => 'ACTIVE',
            'is_superadmin' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ];
    }

    private function createTenants(): void
    {
        DB::table('tenants')->insert([
            $this->tenantData(
                $this->tenantAId,
                'Switch Tenant A',
                'switch-a',
                true,
            ),
            $this->tenantData(
                $this->tenantBId,
                'Switch Tenant B',
                'switch-b',
                true,
            ),
            $this->tenantData(
                $this->inactiveTenantId,
                'Switch Inactive Tenant',
                'switch-inactive',
                false,
            ),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function tenantData(
        string $id,
        string $name,
        string $subdomainPrefix,
        bool $isActive,
    ): array {
        return [
            'id' => $id,
            'name' => $name,
            'subdomain' => sprintf(
                '%s-%s',
                $subdomainPrefix,
                Str::lower(Str::random(8)),
            ),
            'is_active' => $isActive,
            'created_at' => now(),
            'updated_at' => now(),
        ];
    }

    private function createMemberships(): void
    {
        DB::table('memberships')->insert([
            $this->membershipData(
                $this->membershipAId,
                $this->userAId,
                $this->tenantAId,
                'employee-a',
                'ACTIVE',
            ),
            $this->membershipData(
                $this->membershipBId,
                $this->userAId,
                $this->tenantBId,
                'employee-b',
                'ACTIVE',
            ),
            $this->membershipData(
                $this->inactiveMembershipId,
                $this->userAId,
                $this->tenantAId,
                'inactive-membership',
                'SUSPENDED',
            ),
            $this->membershipData(
                $this->inactiveTenantMembershipId,
                $this->userAId,
                $this->inactiveTenantId,
                'inactive-tenant',
                'ACTIVE',
            ),
            $this->membershipData(
                $this->otherUserMembershipId,
                $this->userBId,
                $this->tenantAId,
                'other-user',
                'ACTIVE',
            ),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function membershipData(
        string $id,
        string $userId,
        string $tenantId,
        string $legacyRole,
        string $status,
    ): array {
        return [
            'id' => $id,
            'user_id' => $userId,
            'tenant_id' => $tenantId,
            'role' => $legacyRole,
            'status' => $status,
            'created_at' => now(),
            'updated_at' => now(),
        ];
    }
}
