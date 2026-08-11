<?php

declare(strict_types=1);

namespace Modules\User\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Auth\Token\Contracts\TokenManagerInterface;
use Modules\Core\Support\Uuid\UuidV7;
use Tests\TestCase;

final class AssignMembershipRoleTest extends TestCase
{
    use RefreshDatabase;

    private string $tenantAId;
    private string $tenantBId;

    private string $adminPersonId;
    private string $regularPersonId;
    private string $targetPersonId;
    private string $inactiveTargetPersonId;
    private string $otherTenantPersonId;

    private string $adminUserId;
    private string $regularUserId;
    private string $targetUserId;
    private string $inactiveTargetUserId;
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

        $this->tenantAId = UuidV7::generate();
        $this->tenantBId = UuidV7::generate();

        $this->adminPersonId = UuidV7::generate();
        $this->regularPersonId = UuidV7::generate();
        $this->targetPersonId = UuidV7::generate();
        $this->inactiveTargetPersonId = UuidV7::generate();
        $this->otherTenantPersonId = UuidV7::generate();

        $this->adminUserId = UuidV7::generate();
        $this->regularUserId = UuidV7::generate();
        $this->targetUserId = UuidV7::generate();
        $this->inactiveTargetUserId = UuidV7::generate();
        $this->otherTenantUserId = UuidV7::generate();

        $this->adminMembershipId = UuidV7::generate();
        $this->regularMembershipId = UuidV7::generate();
        $this->targetMembershipId = UuidV7::generate();
        $this->inactiveMembershipId = UuidV7::generate();
        $this->otherTenantMembershipId = UuidV7::generate();

        $this->adminRoleId = UuidV7::generate();
        $this->employeeRoleId = UuidV7::generate();

        $this->createTenants();
        $this->createPersons();
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
                ['role_id' => $this->employeeRoleId],
            );

        $response
            ->assertOk()
            ->assertJsonPath('status', 'success')
            ->assertJsonPath(
                'data.target_membership_id',
                $this->targetMembershipId,
            )
            ->assertJsonPath('data.role_id', $this->employeeRoleId);

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
                ['role_id' => $this->employeeRoleId],
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
                ['role_id' => $this->employeeRoleId],
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
                ['role_id' => $this->employeeRoleId],
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
        DB::table('membership_roles')->insert([
            'membership_id' => $this->targetMembershipId,
            'role_id' => $this->employeeRoleId,
        ]);

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
                ['role_id' => $this->employeeRoleId],
            );

        $response
            ->assertOk()
            ->assertJsonPath('status', 'success')
            ->assertJsonPath(
                'data.target_membership_id',
                $this->targetMembershipId,
            )
            ->assertJsonPath('data.role_id', $this->employeeRoleId);

        $this->assertSame(
            1,
            DB::table('membership_roles')
                ->where('membership_id', $this->targetMembershipId)
                ->where('role_id', $this->employeeRoleId)
                ->count(),
        );
    }

    public function test_assignment_rejects_uuid_v4_role_id(): void
    {
        $token = $this->issueToken(
            userId: $this->adminUserId,
            tenantId: $this->tenantAId,
            membershipId: $this->adminMembershipId,
        );

        $this
            ->withToken($token)
            ->postJson(
                sprintf(
                    '/api/v1/user/memberships/%s/assign-role',
                    $this->targetMembershipId,
                ),
                ['role_id' => (string) Str::uuid()],
            )
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['role_id']);
    }

    public function test_assignment_rejects_unknown_uuid_v7_role_id(): void
    {
        $token = $this->issueToken(
            userId: $this->adminUserId,
            tenantId: $this->tenantAId,
            membershipId: $this->adminMembershipId,
        );

        $this
            ->withToken($token)
            ->postJson(
                sprintf(
                    '/api/v1/user/memberships/%s/assign-role',
                    $this->targetMembershipId,
                ),
                ['role_id' => UuidV7::generate()],
            )
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['role_id']);
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

    private function createPersons(): void
    {
        DB::table('persons')->insert([
            $this->personData($this->adminPersonId, 'Assignment Admin'),
            $this->personData($this->regularPersonId, 'Assignment Regular'),
            $this->personData($this->targetPersonId, 'Assignment Target'),
            $this->personData($this->inactiveTargetPersonId, 'Inactive Target'),
            $this->personData($this->otherTenantPersonId, 'Other Tenant Target'),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function personData(string $id, string $name): array
    {
        return [
            'id' => $id,
            'name' => $name,
            'status' => 'ACTIVE',
            'created_at' => now(),
            'updated_at' => now(),
        ];
    }

    private function createUsers(): void
    {
        DB::table('users')->insert([
            $this->userData(
                $this->adminUserId,
                $this->adminPersonId,
                'assignment-admin',
            ),
            $this->userData(
                $this->regularUserId,
                $this->regularPersonId,
                'assignment-regular',
            ),
            $this->userData(
                $this->targetUserId,
                $this->targetPersonId,
                'assignment-target',
            ),
            $this->userData(
                $this->inactiveTargetUserId,
                $this->inactiveTargetPersonId,
                'assignment-inactive-target',
            ),
            $this->userData(
                $this->otherTenantUserId,
                $this->otherTenantPersonId,
                'assignment-other-tenant',
            ),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function userData(
        string $id,
        string $personId,
        string $emailPrefix,
    ): array {
        return [
            'id' => $id,
            'person_id' => $personId,
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
                $this->adminMembershipId,
                $this->adminPersonId,
                $this->tenantAId,
                'ACTIVE',
            ),
            $this->membershipData(
                $this->regularMembershipId,
                $this->regularPersonId,
                $this->tenantAId,
                'ACTIVE',
            ),
            $this->membershipData(
                $this->targetMembershipId,
                $this->targetPersonId,
                $this->tenantAId,
                'ACTIVE',
            ),
            $this->membershipData(
                $this->inactiveMembershipId,
                $this->inactiveTargetPersonId,
                $this->tenantAId,
                'SUSPENDED',
            ),
            $this->membershipData(
                $this->otherTenantMembershipId,
                $this->otherTenantPersonId,
                $this->tenantBId,
                'ACTIVE',
            ),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function membershipData(
        string $id,
        string $personId,
        string $tenantId,
        string $status,
    ): array {
        return [
            'id' => $id,
            'person_id' => $personId,
            'tenant_id' => $tenantId,
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
                ['membership_id' => $membershipId],
            );
    }
}
