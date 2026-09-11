<?php

declare(strict_types=1);

namespace Modules\Core\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Auth\Token\Contracts\TokenManagerInterface;
use Modules\Core\Authorization\Database\Seeders\AuthorizationCatalogSeeder;
use Modules\Core\Organization\Database\Seeders\OrganizationAuthorizationCatalogSeeder;
use Modules\Core\Support\Uuid\UuidV7;
use Modules\Core\Tenancy\Contracts\TenantContextInterface;
use Symfony\Component\HttpFoundation\Response;
use Tests\Support\GrantsAuthorizationRole;
use Tests\TestCase;

final class OrganizationUnitManagementControllerTest extends TestCase
{
    use GrantsAuthorizationRole;
    use RefreshDatabase;

    private string $tenantId;

    private string $operatorUserId;

    private string $operatorMembershipId;

    private string $organizationId;

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
            'KAMPUS-UTAMA',
        );
    }

    protected function tearDown(): void
    {
        app(TenantContextInterface::class)->clear();

        parent::tearDown();
    }

    public function test_index_lists_units_for_the_given_organization(): void
    {
        $this->createUnitFixture(
            $this->tenantId,
            $this->organizationId,
            'Fakultas Teknik',
            'FT',
        );

        $otherOrganizationId = $this->createOrganizationFixture(
            $this->tenantId,
            'Kampus Cabang',
            'KAMPUS-CABANG',
        );
        $this->createUnitFixture(
            $this->tenantId,
            $otherOrganizationId,
            'Milik Organization Lain',
            null,
        );

        $response = $this
            ->withToken($this->issueToken())
            ->getJson(
                route(
                    'api.v1.core.organizations.units.index',
                    ['organization' => $this->organizationId],
                    false,
                ),
            );

        $response->assertOk();

        $names = collect($response->json('data'))->pluck('name');

        $this->assertTrue($names->contains('Fakultas Teknik'));
        $this->assertFalse(
            $names->contains('Milik Organization Lain'),
            'Units belonging to another Organization must never appear.',
        );
    }

    public function test_index_returns_404_when_organization_belongs_to_another_tenant(): void
    {
        $otherTenantId = UuidV7::generate();
        $this->createTenantFixture($otherTenantId);
        $foreignOrganizationId = $this->createOrganizationFixture(
            $otherTenantId,
            'Milik Tenant Lain',
            null,
        );

        $response = $this
            ->withToken($this->issueToken())
            ->getJson(
                route(
                    'api.v1.core.organizations.units.index',
                    ['organization' => $foreignOrganizationId],
                    false,
                ),
            );

        $response->assertStatus(Response::HTTP_NOT_FOUND);
    }

    public function test_store_creates_new_unit(): void
    {
        $response = $this
            ->withToken($this->issueToken())
            ->postJson(
                route(
                    'api.v1.core.organizations.units.store',
                    ['organization' => $this->organizationId],
                    false,
                ),
                [
                    'name' => 'Fakultas Teknik',
                    'code' => 'FT',
                ],
            );

        $response->assertStatus(Response::HTTP_CREATED);
        $response->assertJsonPath('data.name', 'Fakultas Teknik');
        $response->assertJsonPath('data.code', 'FT');
        $response->assertJsonPath('data.organization_id', $this->organizationId);
        $response->assertJsonPath('data.is_active', true);

        $this->assertDatabaseHas('organization_units', [
            'tenant_id' => $this->tenantId,
            'organization_id' => $this->organizationId,
            'name' => 'Fakultas Teknik',
            'code' => 'FT',
        ]);
    }

    public function test_store_allows_omitted_code(): void
    {
        $response = $this
            ->withToken($this->issueToken())
            ->postJson(
                route(
                    'api.v1.core.organizations.units.store',
                    ['organization' => $this->organizationId],
                    false,
                ),
                [
                    'name' => 'Fakultas Teknik',
                ],
            );

        $response->assertStatus(Response::HTTP_CREATED);
        $response->assertJsonPath('data.code', null);
    }

    public function test_store_rejects_duplicate_code_within_same_organization(): void
    {
        $this->createUnitFixture(
            $this->tenantId,
            $this->organizationId,
            'Fakultas Teknik',
            'FT',
        );

        $response = $this
            ->withToken($this->issueToken())
            ->postJson(
                route(
                    'api.v1.core.organizations.units.store',
                    ['organization' => $this->organizationId],
                    false,
                ),
                [
                    'name' => 'Fakultas Teknik (Duplikat)',
                    'code' => 'FT',
                ],
            );

        $response->assertStatus(Response::HTTP_UNPROCESSABLE_ENTITY);
    }

    public function test_store_allows_same_code_across_different_organizations(): void
    {
        $otherOrganizationId = $this->createOrganizationFixture(
            $this->tenantId,
            'Kampus Cabang',
            'KAMPUS-CABANG',
        );
        $this->createUnitFixture(
            $this->tenantId,
            $otherOrganizationId,
            'Rektorat',
            'REKTORAT',
        );

        $response = $this
            ->withToken($this->issueToken())
            ->postJson(
                route(
                    'api.v1.core.organizations.units.store',
                    ['organization' => $this->organizationId],
                    false,
                ),
                [
                    'name' => 'Rektorat',
                    'code' => 'REKTORAT',
                ],
            );

        $response->assertStatus(Response::HTTP_CREATED);
    }

    public function test_store_returns_404_when_organization_belongs_to_another_tenant(): void
    {
        $otherTenantId = UuidV7::generate();
        $this->createTenantFixture($otherTenantId);
        $foreignOrganizationId = $this->createOrganizationFixture(
            $otherTenantId,
            'Milik Tenant Lain',
            null,
        );

        $response = $this
            ->withToken($this->issueToken())
            ->postJson(
                route(
                    'api.v1.core.organizations.units.store',
                    ['organization' => $foreignOrganizationId],
                    false,
                ),
                [
                    'name' => 'Unit Ilegal',
                ],
            );

        $response->assertStatus(Response::HTTP_NOT_FOUND);
    }

    public function test_index_is_forbidden_without_organization_units_manage_permission(): void
    {
        DB::table('membership_roles')
            ->where('membership_id', $this->operatorMembershipId)
            ->delete();

        $response = $this
            ->withToken($this->issueToken())
            ->getJson(
                route(
                    'api.v1.core.organizations.units.index',
                    ['organization' => $this->organizationId],
                    false,
                ),
            );

        $response->assertStatus(Response::HTTP_FORBIDDEN);
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
            'name' => 'Organization Unit Management Tenant',
            'subdomain' => sprintf(
                'org-unit-mgmt-%s',
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
            'name' => 'Organization Unit Management Operator',
            'status' => 'ACTIVE',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $email = sprintf(
            'org-unit-mgmt-operator-%s@educore.test',
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

    private function createOrganizationFixture(
        string $tenantId,
        string $name,
        ?string $code,
    ): string {
        $organizationId = UuidV7::generate();

        DB::table('organizations')->insert([
            'id' => $organizationId,
            'tenant_id' => $tenantId,
            'name' => $name,
            'code' => $code,
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
        ?string $code,
    ): string {
        $unitId = UuidV7::generate();

        DB::table('organization_units')->insert([
            'id' => $unitId,
            'tenant_id' => $tenantId,
            'organization_id' => $organizationId,
            'name' => $name,
            'code' => $code,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $unitId;
    }
}
