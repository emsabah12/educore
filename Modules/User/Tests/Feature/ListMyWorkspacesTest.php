<?php

declare(strict_types=1);

namespace Modules\User\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Modules\Auth\Token\Contracts\TokenManagerInterface;
use Modules\Core\Identity\Models\User;
use Modules\Core\Support\Uuid\UuidV7;
use Tests\TestCase;

final class ListMyWorkspacesTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private User $otherUser;

    private string $tenantId;

    private string $otherTenantId;

    private string $membershipId;

    private string $otherMembershipId;

    private string $otherTenantMembershipId;

    private string $organizationId;

    private string $organizationAssignmentId;

    private string $inactiveAssignmentOrganizationId;

    private string $unitId;

    private string $unitAssignmentId;

    private string $inactiveOrganizationId;

    private string $inactiveUnitId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $this->otherUser = User::factory()->create();

        $this->tenantId = UuidV7::generate();
        $this->otherTenantId = UuidV7::generate();

        $this->membershipId = UuidV7::generate();
        $this->otherMembershipId = UuidV7::generate();
        $this->otherTenantMembershipId = UuidV7::generate();

        $this->organizationId = UuidV7::generate();
        $this->organizationAssignmentId = UuidV7::generate();

        $this->inactiveAssignmentOrganizationId = UuidV7::generate();

        $this->unitId = UuidV7::generate();
        $this->unitAssignmentId = UuidV7::generate();

        $this->inactiveOrganizationId = UuidV7::generate();
        $this->inactiveUnitId = UuidV7::generate();

        $this->createTenants();
        $this->createMemberships();
        $this->createOrganizations();
        $this->createOrganizationUnits();
        $this->createAssignments();
    }

    public function test_authenticated_user_can_discover_only_active_workspaces_for_current_membership_and_tenant(): void
    {
        $response = $this
            ->withToken($this->currentToken())
            ->getJson('/api/v1/user/my-workspaces');

        $response
            ->assertOk()
            ->assertJsonPath(
                'status',
                'success',
            )
            ->assertJsonPath(
                'data.tenant.id',
                $this->tenantId,
            )
            ->assertJsonPath(
                'data.tenant.name',
                'Workspace Tenant',
            )
            ->assertJsonCount(
                3,
                'data.workspaces',
            );

        /*
         * Tenant-level workspace selalu tersedia untuk current
         * verified Membership/Tenant.
         */
        $response->assertJsonFragment([
            'type' => 'TENANT',
            'organizational_assignment_id' => null,
            'organization_id' => null,
            'organization_unit_id' => null,
            'label' => 'Workspace Tenant',
        ]);

        /*
         * Organization-level active assignment.
         */
        $response->assertJsonFragment([
            'type' => 'ORGANIZATION',
            'organizational_assignment_id' =>
            $this->organizationAssignmentId,
            'organization_id' =>
            $this->organizationId,
            'organization_unit_id' => null,
            'label' => 'SMA Workspace',
        ]);

        /*
         * Unit-level active assignment.
         */
        $response->assertJsonFragment([
            'type' => 'ORGANIZATION_UNIT',
            'organizational_assignment_id' =>
            $this->unitAssignmentId,
            'organization_id' =>
            $this->organizationId,
            'organization_unit_id' =>
            $this->unitId,
            'label' => 'Unit Kurikulum',
        ]);

        /*
         * Inactive/cross-membership/cross-tenant placements tidak
         * boleh muncul di frontend projection.
         */
        $payload = $response->json(
            'data.workspaces',
        );

        $this->assertIsArray($payload);

        $assignmentIds = collect($payload)
            ->pluck('organizational_assignment_id')
            ->filter()
            ->values()
            ->all();

        $this->assertSame(
            [
                $this->organizationAssignmentId,
                $this->unitAssignmentId,
            ],
            $assignmentIds,
        );
    }

    public function test_workspace_discovery_requires_verified_membership_and_tenant_context(): void
    {
        $this
            ->getJson('/api/v1/user/my-workspaces')
            ->assertForbidden()
            ->assertJsonPath(
                'status',
                'error',
            );
    }

    public function test_token_for_other_tenant_cannot_discover_current_tenant_assignments(): void
    {
        $token = app(TokenManagerInterface::class)
            ->issueToken(
                (string) $this->user->getKey(),
                $this->otherTenantId,
                [
                    'membership_id' =>
                    $this->otherTenantMembershipId,
                ],
            );

        $response = $this
            ->withToken($token)
            ->getJson('/api/v1/user/my-workspaces');

        $response
            ->assertOk()
            ->assertJsonPath(
                'data.tenant.id',
                $this->otherTenantId,
            )
            ->assertJsonCount(
                1,
                'data.workspaces',
            )
            ->assertJsonFragment([
                'type' => 'TENANT',
                'label' => 'Other Workspace Tenant',
            ]);

        $this->assertFalse(
            collect(
                $response->json(
                    'data.workspaces',
                ),
            )->contains(
                'organizational_assignment_id',
                $this->organizationAssignmentId,
            ),
        );
    }

    private function currentToken(): string
    {
        return app(TokenManagerInterface::class)
            ->issueToken(
                (string) $this->user->getKey(),
                $this->tenantId,
                [
                    'membership_id' =>
                    $this->membershipId,
                ],
            );
    }

    private function createTenants(): void
    {
        DB::table('tenants')->insert([
            [
                'id' => $this->tenantId,
                'name' => 'Workspace Tenant',
                'subdomain' => 'workspace-main',
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'id' => $this->otherTenantId,
                'name' => 'Other Workspace Tenant',
                'subdomain' => 'workspace-other',
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);
    }

    private function createMemberships(): void
    {
        DB::table('memberships')->insert([
            [
                'id' => $this->membershipId,
                'person_id' =>
                (string) $this->user->person_id,
                'tenant_id' => $this->tenantId,
                'status' => 'ACTIVE',
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'id' => $this->otherMembershipId,
                'person_id' =>
                (string) $this->otherUser->person_id,
                'tenant_id' => $this->tenantId,
                'status' => 'ACTIVE',
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'id' => $this->otherTenantMembershipId,
                'person_id' =>
                (string) $this->user->person_id,
                'tenant_id' => $this->otherTenantId,
                'status' => 'ACTIVE',
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);
    }

    private function createOrganizations(): void
    {
        DB::table('organizations')->insert([
            [
                'id' => $this->organizationId,
                'tenant_id' => $this->tenantId,
                'name' => 'SMA Workspace',
                'code' => 'SMA',
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
                'deleted_at' => null,
            ],
            [
                /*
             * Organization tetap ACTIVE.
             *
             * Ini khusus untuk membuktikan bahwa workspace tidak muncul
             * karena OrganizationalAssignment-nya INACTIVE, bukan karena
             * Organization-nya inactive.
             */
                'id' => $this->inactiveAssignmentOrganizationId,
                'tenant_id' => $this->tenantId,
                'name' => 'Inactive Assignment Workspace',
                'code' => 'INACTIVE-ASSIGNMENT',
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
                'deleted_at' => null,
            ],
            [
                /*
             * Organization ini sendiri inactive.
             *
             * Fixture terpisah untuk menguji filtering topology.
             */
                'id' => $this->inactiveOrganizationId,
                'tenant_id' => $this->tenantId,
                'name' => 'Inactive Organization',
                'code' => 'INACTIVE',
                'is_active' => false,
                'created_at' => now(),
                'updated_at' => now(),
                'deleted_at' => null,
            ],
        ]);
    }

    private function createOrganizationUnits(): void
    {
        DB::table('organization_units')->insert([
            [
                'id' => $this->unitId,
                'tenant_id' => $this->tenantId,
                'organization_id' =>
                $this->organizationId,
                'name' => 'Unit Kurikulum',
                'code' => 'KURIKULUM',
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
                'deleted_at' => null,
            ],
            [
                'id' => $this->inactiveUnitId,
                'tenant_id' => $this->tenantId,
                'organization_id' =>
                $this->organizationId,
                'name' => 'Inactive Unit',
                'code' => 'INACTIVE',
                'is_active' => false,
                'created_at' => now(),
                'updated_at' => now(),
                'deleted_at' => null,
            ],
        ]);
    }

    private function createAssignments(): void
    {
        DB::table('organizational_assignments')->insert([
            /*
             * Current Membership — active organization workspace.
             */
            [
                'id' =>
                $this->organizationAssignmentId,
                'tenant_id' =>
                $this->tenantId,
                'membership_id' =>
                $this->membershipId,
                'organization_id' =>
                $this->organizationId,
                'organization_unit_id' => null,
                'status' => 'ACTIVE',
                'created_at' => now(),
                'updated_at' => now(),
            ],

            /*
             * Current Membership — active unit workspace.
             */
            [
                'id' =>
                $this->unitAssignmentId,
                'tenant_id' =>
                $this->tenantId,
                'membership_id' =>
                $this->membershipId,
                'organization_id' =>
                $this->organizationId,
                'organization_unit_id' =>
                $this->unitId,
                'status' => 'ACTIVE',
                'created_at' => now(),
                'updated_at' => now(),
            ],


            /*
             * Active assignment tetapi Organization inactive.
             */
            [
                /*
                * Assignment INACTIVE terhadap Organization ACTIVE yang berbeda.
                *
                * Harus dikeluarkan murni karena assignment status, tanpa
                * melanggar unique organization-level assignment invariant.
                */
                'id' => UuidV7::generate(),
                'tenant_id' =>
                $this->tenantId,
                'membership_id' =>
                $this->membershipId,
                'organization_id' =>
                $this->inactiveAssignmentOrganizationId,
                'organization_unit_id' => null,
                'status' => 'INACTIVE',
                'created_at' => now(),
                'updated_at' => now(),
            ],

            /*
             * Active assignment tetapi Unit inactive.
             */
            [
                'id' => UuidV7::generate(),
                'tenant_id' =>
                $this->tenantId,
                'membership_id' =>
                $this->membershipId,
                'organization_id' =>
                $this->organizationId,
                'organization_unit_id' =>
                $this->inactiveUnitId,
                'status' => 'ACTIVE',
                'created_at' => now(),
                'updated_at' => now(),
            ],

            /*
             * Membership Person lain dalam Tenant yang sama.
             */
            [
                'id' => UuidV7::generate(),
                'tenant_id' =>
                $this->tenantId,
                'membership_id' =>
                $this->otherMembershipId,
                'organization_id' =>
                $this->organizationId,
                'organization_unit_id' => null,
                'status' => 'ACTIVE',
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);
    }
}
