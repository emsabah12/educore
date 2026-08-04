<?php

declare(strict_types=1);

namespace Modules\User\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Auth\Token\Contracts\TokenManagerInterface;
use Tests\TestCase;

final class AssignMembershipRoleTest extends TestCase
{
    use RefreshDatabase;

    private string $tenantAId;
    private string $tenantBId;

    private string $adminUserId;
    private string $regularUserId;
    private string $targetUserId;
    private string $otherTenantUserId;

    private string $adminMembershipId;
    private string $regularMembershipId;
    private string $targetMembershipId;
    private string $inactiveMembershipId;
    private string $otherTenantMembershipId;

    private string $adminRoleId;
    private string $employeeRoleId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenantAId = Str::uuid()->toString();
        $this->tenantBId = Str::uuid()->toString();

        $this->adminUserId = Str::uuid()->toString();
        $this->regularUserId = Str::uuid()->toString();
        $this->targetUserId = Str::uuid()->toString();
        $this->otherTenantUserId = Str::uuid()->toString();

        $this->adminMembershipId = Str::uuid()->toString();
        $this->regularMembershipId = Str::uuid()->toString();
        $this->targetMembershipId = Str::uuid()->toString();
        $this->inactiveMembershipId = Str::uuid()->toString();
        $this->otherTenantMembershipId = Str::uuid()->toString();

        $this->adminRoleId = Str::uuid()->toString();
        $this->employeeRoleId = Str::uuid()->toString();

        $this->createTenants();
        $this->createUsers();
        $this->createMemberships();
        $this->createRoles();
        $this->attachAdminRole();
    }

    public function test_admin_can_assign_role_to_active_membership_in_same_tenant(): void
    {
        $token = $this->issueToken(
            userId: $this->adminUserId,
            tenantId: $this->tenantAId,
            membershipId: $this->adminMembershipId,
        );

        $response = $this
            ->withToken($token)
            ->postJson(
                sprintf(
                    '/api/v1/user/memberships/%s/assign-role',
                    $this->targetMembershipId,
                ),
                [
                    'role_id' => $this->employeeRoleId,
                ],
            );

        $response
            ->assertOk()
            ->assertJsonPath('status', 'success')
            ->assertJsonPath(
                'data.target_membership_id',
                $this->targetMembershipId,
            )
            ->assertJsonPath(
                'data.role_id',
                $this->employeeRoleId,
            );

        $this->assertDatabaseHas('membership_roles', [
            'membership_id' => $this->targetMembershipId,
            'role_id' => $this->employeeRoleId,
        ]);
    }

    public function test_non_admin_cannot_assign_role(): void
    {
        $token = $this->issueToken(
            userId: $this->regularUserId,
            tenantId: $this->tenantAId,
            membershipId: $this->regularMembershipId,
        );

        $response = $this
            ->withToken($token)
            ->postJson(
                sprintf(
                    '/api/v1/user/memberships/%s/assign-role',
                    $this->targetMembershipId,
                ),
                [
                    'role_id' => $this->employeeRoleId,
                ],
            );

        $response->assertForbidden();

        $this->assertDatabaseMissing('membership_roles', [
            'membership_id' => $this->targetMembershipId,
            'role_id' => $this->employeeRoleId,
        ]);
    }

    public function test_admin_cannot_assign_role_to_membership_from_another_tenant(): void
    {
        $token = $this->issueToken(
            userId: $this->adminUserId,
            tenantId: $this->tenantAId,
            membershipId: $this->adminMembershipId,
        );

        $response = $this
            ->withToken($token)
            ->postJson(
                sprintf(
                    '/api/v1/user/memberships/%s/assign-role',
                    $this->otherTenantMembershipId,
                ),
                [
                    'role_id' => $this->employeeRoleId,
                ],
            );

        $response
            ->assertNotFound()
            ->assertJsonPath('status', 'error');

        $this->assertDatabaseMissing('membership_roles', [
            'membership_id' => $this->otherTenantMembershipId,
            'role_id' => $this->employeeRoleId,
        ]);
    }

    public function test_admin_cannot_assign_role_to_inactive_membership(): void
    {
        $token = $this->issueToken(
            userId: $this->adminUserId,
            tenantId: $this->tenantAId,
            membershipId: $this->adminMembershipId,
        );

        $response = $this
            ->withToken($token)
            ->postJson(
                sprintf(
                    '/api/v1/user/memberships/%s/assign-role',
                    $this->inactiveMembershipId,
                ),
                [
                    'role_id' => $this->employeeRoleId,
                ],
            );

        $response
            ->assertNotFound()
            ->assertJsonPath('status', 'error');

        $this->assertDatabaseMissing('membership_roles', [
            'membership_id' => $this->inactiveMembershipId,
            'role_id' => $this->employeeRoleId,
        ]);
    }

    public function test_assigning_same_role_twice_is_idempotent(): void
    {
        $token = $this->issueToken(
            userId: $this->adminUserId,
            tenantId: $this->tenantAId,
            membershipId: $this->adminMembershipId,
        );

        $uri = sprintf(
            '/api/v1/user/memberships/%s/assign-role',
            $this->targetMembershipId,
        );

        $payload = [
            'role_id' => $this->employeeRoleId,
        ];

        $this
            ->withToken($token)
            ->postJson($uri, $payload)
            ->assertOk();

        $this
            ->withToken($token)
            ->postJson($uri, $payload)
            ->assertOk();

        $assignmentCount = DB::table('membership_roles')
            ->where(
                'membership_id',
                $this->targetMembershipId,
            )
            ->where(
                'role_id',
                $this->employeeRoleId,
            )
            ->count();

        $this->assertSame(
            1,
            $assignmentCount,
        );
    }

    private function createTenants(): void
    {
        DB::table('tenants')->insert([
            [
                'id' => $this->tenantAId,
                'name' => 'Assignment Tenant A',
                'subdomain' => sprintf(
                    'assignment-a-%s',
                    Str::lower(Str::random(8)),
                ),
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'id' => $this->tenantBId,
                'name' => 'Assignment Tenant B',
                'subdomain' => sprintf(
                    'assignment-b-%s',
                    Str::lower(Str::random(8)),
                ),
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);
    }

    private function createUsers(): void
    {
        DB::table('users')->insert([
            $this->userData(
                id: $this->adminUserId,
                name: 'Assignment Admin',
                emailPrefix: 'assignment-admin',
            ),
            $this->userData(
                id: $this->regularUserId,
                name: 'Assignment Regular User',
                emailPrefix: 'assignment-regular',
            ),
            $this->userData(
                id: $this->targetUserId,
                name: 'Assignment Target User',
                emailPrefix: 'assignment-target',
            ),
            $this->userData(
                id: $this->otherTenantUserId,
                name: 'Other Tenant Target User',
                emailPrefix: 'assignment-other-tenant',
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

    private function createMemberships(): void
    {
        DB::table('memberships')->insert([
            $this->membershipData(
                id: $this->adminMembershipId,
                userId: $this->adminUserId,
                tenantId: $this->tenantAId,
                legacyRole: 'admin',
                status: 'ACTIVE',
            ),
            $this->membershipData(
                id: $this->regularMembershipId,
                userId: $this->regularUserId,
                tenantId: $this->tenantAId,
                legacyRole: 'employee',
                status: 'ACTIVE',
            ),
            $this->membershipData(
                id: $this->targetMembershipId,
                userId: $this->targetUserId,
                tenantId: $this->tenantAId,
                legacyRole: 'employee',
                status: 'ACTIVE',
            ),
            $this->membershipData(
                id: $this->inactiveMembershipId,
                userId: $this->targetUserId,
                tenantId: $this->tenantAId,
                legacyRole: 'inactive',
                status: 'SUSPENDED',
            ),
            $this->membershipData(
                id: $this->otherTenantMembershipId,
                userId: $this->otherTenantUserId,
                tenantId: $this->tenantBId,
                legacyRole: 'employee',
                status: 'ACTIVE',
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

            /*
             * Hanya untuk kompatibilitas skema lama.
             * Authorization tidak membaca memberships.role.
             */
            'role' => $legacyRole,

            'status' => $status,
            'created_at' => now(),
            'updated_at' => now(),
        ];
    }

    private function createRoles(): void
    {
        DB::table('roles')->insert([
            [
                'id' => $this->adminRoleId,
                'name' => 'admin',
                'display_name' => 'Administrator',
                'description' => 'Tenant administrator role.',
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'id' => $this->employeeRoleId,
                'name' => 'employee',
                'display_name' => 'Employee',
                'description' => 'Tenant employee role.',
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);
    }

    private function attachAdminRole(): void
    {
        DB::table('membership_roles')->insert([
            'membership_id' => $this->adminMembershipId,
            'role_id' => $this->adminRoleId,
        ]);
    }

    private function issueToken(
        string $userId,
        string $tenantId,
        string $membershipId,
    ): string {
        return app(TokenManagerInterface::class)
            ->issueToken(
                $userId,
                $tenantId,
                [
                    'membership_id' => $membershipId,
                ],
            );
    }
}
