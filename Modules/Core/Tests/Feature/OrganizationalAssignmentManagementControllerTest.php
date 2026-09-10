<?php

declare(strict_types=1);

namespace Modules\Core\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Auth\Token\Contracts\TokenManagerInterface;
use Modules\Core\Authorization\Database\Seeders\AuthorizationCatalogSeeder;
use Modules\Core\Organization\Database\Seeders\OrganizationAuthorizationCatalogSeeder;
use Modules\Core\Organization\Models\OrganizationalAssignment;
use Modules\Core\Support\Uuid\UuidV7;
use Modules\Core\Tenancy\Contracts\TenantContextInterface;
use Symfony\Component\HttpFoundation\Response;
use Tests\Support\GrantsAuthorizationRole;
use Tests\TestCase;

final class OrganizationalAssignmentManagementControllerTest extends TestCase
{
    use RefreshDatabase;
    use GrantsAuthorizationRole;

    private string $tenantId;
    private string $operatorUserId;
    private string $operatorMembershipId;
    private string $organizationId;
    private string $unitId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(AuthorizationCatalogSeeder::class);
        $this->seed(OrganizationAuthorizationCatalogSeeder::class);

        $this->tenantId = UuidV7::generate();
        $this->operatorUserId = UuidV7::generate();
        $this->operatorMembershipId = UuidV7::generate();

        $this->createTenantFixture($this->tenantId);
        $this->createOperatorFixture();

        $this->grantRole(
            $this->operatorMembershipId,
            'admin',
        );

        $this->organizationId = $this->createOrganizationFixture(
            $this->tenantId,
            'Kampus Utama',
        );

        $this->unitId = $this->createUnitFixture(
            $this->tenantId,
            $this->organizationId,
            'Fakultas Teknik',
        );
    }

    protected function tearDown(): void
    {
        app(TenantContextInterface::class)->clear();

        parent::tearDown();
    }

    public function test_index_lists_organization_level_and_unit_level_assignments_together(): void
    {
        $orgLevelMembershipId = $this->createMembershipFixture(
            $this->tenantId,
            'Budi Santoso',
        );
        $this->createAssignmentFixture(
            $this->tenantId,
            $orgLevelMembershipId,
            $this->organizationId,
            null,
        );

        $unitLevelMembershipId = $this->createMembershipFixture(
            $this->tenantId,
            'Siti Aminah',
        );
        $this->createAssignmentFixture(
            $this->tenantId,
            $unitLevelMembershipId,
            $this->organizationId,
            $this->unitId,
        );

        $response = $this
            ->withToken($this->issueToken())
            ->getJson(
                route(
                    'api.v1.core.organizations.assignments.index',
                    ['organization' => $this->organizationId],
                    false,
                ),
            );

        $response->assertOk();

        $names = collect($response->json('data'))->pluck('membership_name');

        $this->assertTrue($names->contains('Budi Santoso'));
        $this->assertTrue($names->contains('Siti Aminah'));
    }

    public function test_index_reports_null_organization_unit_id_for_organization_level_assignment(): void
    {
        $membershipId = $this->createMembershipFixture(
            $this->tenantId,
            'Budi Santoso',
        );
        $this->createAssignmentFixture(
            $this->tenantId,
            $membershipId,
            $this->organizationId,
            null,
        );

        $response = $this
            ->withToken($this->issueToken())
            ->getJson(
                route(
                    'api.v1.core.organizations.assignments.index',
                    ['organization' => $this->organizationId],
                    false,
                ),
            );

        $response->assertOk();
        $response->assertJsonPath('data.0.organization_unit_id', null);
        $response->assertJsonPath('data.0.organization_unit_name', null);
    }

    public function test_index_reports_unit_name_for_unit_level_assignment(): void
    {
        $membershipId = $this->createMembershipFixture(
            $this->tenantId,
            'Siti Aminah',
        );
        $this->createAssignmentFixture(
            $this->tenantId,
            $membershipId,
            $this->organizationId,
            $this->unitId,
        );

        $response = $this
            ->withToken($this->issueToken())
            ->getJson(
                route(
                    'api.v1.core.organizations.assignments.index',
                    ['organization' => $this->organizationId],
                    false,
                ),
            );

        $response->assertOk();
        $response->assertJsonPath('data.0.organization_unit_id', $this->unitId);
        $response->assertJsonPath('data.0.organization_unit_name', 'Fakultas Teknik');
    }

    public function test_index_filters_by_organization_unit_id(): void
    {
        $orgLevelMembershipId = $this->createMembershipFixture(
            $this->tenantId,
            'Budi Santoso',
        );
        $this->createAssignmentFixture(
            $this->tenantId,
            $orgLevelMembershipId,
            $this->organizationId,
            null,
        );

        $unitLevelMembershipId = $this->createMembershipFixture(
            $this->tenantId,
            'Siti Aminah',
        );
        $this->createAssignmentFixture(
            $this->tenantId,
            $unitLevelMembershipId,
            $this->organizationId,
            $this->unitId,
        );

        $response = $this
            ->withToken($this->issueToken())
            ->getJson(
                route(
                    'api.v1.core.organizations.assignments.index',
                    ['organization' => $this->organizationId],
                    false,
                ) . '?organization_unit_id=' . $this->unitId,
            );

        $response->assertOk();

        $names = collect($response->json('data'))->pluck('membership_name');

        $this->assertTrue($names->contains('Siti Aminah'));
        $this->assertFalse(
            $names->contains('Budi Santoso'),
            'Organization-level assignment must not appear when filtering by a specific Unit.',
        );
    }

    public function test_index_excludes_assignments_belonging_to_another_organization(): void
    {
        $otherOrganizationId = $this->createOrganizationFixture(
            $this->tenantId,
            'Kampus Cabang',
        );
        $otherMembershipId = $this->createMembershipFixture(
            $this->tenantId,
            'Milik Organization Lain',
        );
        $this->createAssignmentFixture(
            $this->tenantId,
            $otherMembershipId,
            $otherOrganizationId,
            null,
        );

        $response = $this
            ->withToken($this->issueToken())
            ->getJson(
                route(
                    'api.v1.core.organizations.assignments.index',
                    ['organization' => $this->organizationId],
                    false,
                ),
            );

        $response->assertOk();
        $response->assertJsonCount(0, 'data');
    }

    public function test_index_returns_404_when_organization_belongs_to_another_tenant(): void
    {
        $otherTenantId = UuidV7::generate();
        $this->createTenantFixture($otherTenantId);
        $foreignOrganizationId = $this->createOrganizationFixture(
            $otherTenantId,
            'Milik Tenant Lain',
        );

        $response = $this
            ->withToken($this->issueToken())
            ->getJson(
                route(
                    'api.v1.core.organizations.assignments.index',
                    ['organization' => $foreignOrganizationId],
                    false,
                ),
            );

        $response->assertStatus(Response::HTTP_NOT_FOUND);
    }

    public function test_index_is_forbidden_without_organization_assignments_manage_permission(): void
    {
        DB::table('membership_roles')
            ->where('membership_id', $this->operatorMembershipId)
            ->delete();

        $response = $this
            ->withToken($this->issueToken())
            ->getJson(
                route(
                    'api.v1.core.organizations.assignments.index',
                    ['organization' => $this->organizationId],
                    false,
                ),
            );

        $response->assertStatus(Response::HTTP_FORBIDDEN);
    }

    public function test_index_orders_newest_assignment_first(): void
    {
        $olderMembershipId = $this->createMembershipFixture(
            $this->tenantId,
            'Assignment Lebih Lama',
        );
        $olderAssignmentId = $this->createAssignmentFixture(
            $this->tenantId,
            $olderMembershipId,
            $this->organizationId,
            null,
        );
        OrganizationalAssignment::query()
            ->whereKey($olderAssignmentId)
            ->update(['created_at' => now()->subDay()]);

        $newerMembershipId = $this->createMembershipFixture(
            $this->tenantId,
            'Assignment Lebih Baru',
        );
        $this->createAssignmentFixture(
            $this->tenantId,
            $newerMembershipId,
            $this->organizationId,
            null,
        );

        $response = $this
            ->withToken($this->issueToken())
            ->getJson(
                route(
                    'api.v1.core.organizations.assignments.index',
                    ['organization' => $this->organizationId],
                    false,
                ),
            );

        $response->assertOk();
        $response->assertJsonPath('data.0.membership_name', 'Assignment Lebih Baru');
        $response->assertJsonPath('data.1.membership_name', 'Assignment Lebih Lama');
    }

    private function issueToken(): string
    {
        return app(TokenManagerInterface::class)
            ->issueToken(
                $this->operatorUserId,
                $this->tenantId,
                ['membership_id' => $this->operatorMembershipId],
            );
    }

    private function createTenantFixture(string $tenantId): void
    {
        DB::table('tenants')->insert([
            'id' => $tenantId,
            'name' => 'Organizational Assignment Management Tenant',
            'subdomain' => sprintf(
                'org-assign-mgmt-%s',
                Str::lower(Str::random(12)),
            ),
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function createOperatorFixture(): void
    {
        $personId = UuidV7::generate();

        DB::table('persons')->insert([
            'id' => $personId,
            'name' => 'Organizational Assignment Management Operator',
            'status' => 'ACTIVE',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $email = sprintf(
            'org-assign-mgmt-operator-%s@educore.test',
            Str::lower(Str::random(10)),
        );

        DB::table('users')->insert([
            'id' => $this->operatorUserId,
            'person_id' => $personId,
            'email' => $email,
            'password' => bcrypt('secret123'),
            'status' => 'ACTIVE',
            'is_superadmin' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('memberships')->insert([
            'id' => $this->operatorMembershipId,
            'person_id' => $personId,
            'tenant_id' => $this->tenantId,
            'status' => 'ACTIVE',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function createMembershipFixture(
        string $tenantId,
        string $personName,
    ): string {
        $personId = UuidV7::generate();

        DB::table('persons')->insert([
            'id' => $personId,
            'name' => $personName,
            'status' => 'ACTIVE',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $membershipId = UuidV7::generate();

        DB::table('memberships')->insert([
            'id' => $membershipId,
            'person_id' => $personId,
            'tenant_id' => $tenantId,
            'status' => 'ACTIVE',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $membershipId;
    }

    private function createOrganizationFixture(
        string $tenantId,
        string $name,
    ): string {
        $organizationId = UuidV7::generate();

        DB::table('organizations')->insert([
            'id' => $organizationId,
            'tenant_id' => $tenantId,
            'name' => $name,
            'code' => null,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $organizationId;
    }

    private function createUnitFixture(
        string $tenantId,
        string $organizationId,
        string $name,
    ): string {
        $unitId = UuidV7::generate();

        DB::table('organization_units')->insert([
            'id' => $unitId,
            'tenant_id' => $tenantId,
            'organization_id' => $organizationId,
            'name' => $name,
            'code' => null,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $unitId;
    }

    private function createAssignmentFixture(
        string $tenantId,
        string $membershipId,
        string $organizationId,
        ?string $organizationUnitId,
    ): string {
        $assignmentId = UuidV7::generate();

        DB::table('organizational_assignments')->insert([
            'id' => $assignmentId,
            'tenant_id' => $tenantId,
            'membership_id' => $membershipId,
            'organization_id' => $organizationId,
            'organization_unit_id' => $organizationUnitId,
            'status' => 'ACTIVE',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $assignmentId;
    }
}
