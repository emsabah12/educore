<?php

declare(strict_types=1);

namespace Modules\Core\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Auth\Token\Contracts\TokenManagerInterface;
use Modules\Core\Support\Uuid\UuidV7;
use Tests\TestCase;

final class RoleCatalogApiTest extends TestCase
{
    use RefreshDatabase;

    private string $tenantId;
    private string $adminPersonId;
    private string $regularPersonId;
    private string $adminUserId;
    private string $regularUserId;
    private string $adminMembershipId;
    private string $regularMembershipId;
    private string $adminRoleId;
    private string $customRoleId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenantId = UuidV7::generate();
        $this->adminPersonId = UuidV7::generate();
        $this->regularPersonId = UuidV7::generate();
        $this->adminUserId = UuidV7::generate();
        $this->regularUserId = UuidV7::generate();
        $this->adminMembershipId = UuidV7::generate();
        $this->regularMembershipId = UuidV7::generate();
        $this->adminRoleId = UuidV7::generate();
        $this->customRoleId = UuidV7::generate();

        $this->createFixture();
    }

    public function test_tenant_admin_can_discover_global_roles_with_safe_shape(): void
    {
        $response = $this
            ->withToken($this->issueToken(
                $this->adminUserId,
                $this->adminMembershipId,
            ))
            ->getJson('/api/v1/core/authorization/roles');

        $response
            ->assertOk()
            ->assertJsonPath('status', 'success')
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.name', 'admin')
            ->assertJsonPath('data.1.name', 'custom-operator');

        /** @var array<int, array<string, mixed>> $roles */
        $roles = $response->json('data');

        $this->assertSame(
            ['id', 'name', 'display_name', 'description'],
            array_keys($roles[0]),
        );
        $this->assertSame(
            ['id', 'name', 'display_name', 'description'],
            array_keys($roles[1]),
        );

        $this->assertArrayNotHasKey('created_at', $roles[0]);
        $this->assertArrayNotHasKey('updated_at', $roles[0]);
        $this->assertArrayNotHasKey('permissions', $roles[0]);
    }

    public function test_non_admin_tenant_member_cannot_discover_roles(): void
    {
        $response = $this
            ->withToken($this->issueToken(
                $this->regularUserId,
                $this->regularMembershipId,
            ))
            ->getJson('/api/v1/core/authorization/roles');

        $response->assertForbidden();
    }

    public function test_missing_tenant_authentication_context_cannot_discover_roles(): void
    {
        $this
            ->getJson('/api/v1/core/authorization/roles')
            ->assertForbidden();
    }

    public function test_cross_tenant_membership_context_cannot_discover_roles(): void
    {
        $otherTenantId = UuidV7::generate();

        DB::table('tenants')->insert([
            'id' => $otherTenantId,
            'name' => 'Other Role Catalog Tenant',
            'subdomain' => sprintf(
                'role-catalog-other-%s',
                Str::lower(Str::random(8)),
            ),
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $token = app(TokenManagerInterface::class)
            ->issueToken(
                $this->adminUserId,
                $otherTenantId,
                ['membership_id' => $this->adminMembershipId],
            );

        $this
            ->withToken($token)
            ->getJson('/api/v1/core/authorization/roles')
            ->assertForbidden();
    }

    private function createFixture(): void
    {
        DB::table('tenants')->insert([
            'id' => $this->tenantId,
            'name' => 'Role Catalog Tenant',
            'subdomain' => sprintf(
                'role-catalog-%s',
                Str::lower(Str::random(8)),
            ),
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('persons')->insert([
            $this->personData($this->adminPersonId, 'Role Catalog Admin'),
            $this->personData($this->regularPersonId, 'Role Catalog Member'),
        ]);

        DB::table('users')->insert([
            $this->userData(
                $this->adminUserId,
                $this->adminPersonId,
                'role-catalog-admin',
            ),
            $this->userData(
                $this->regularUserId,
                $this->regularPersonId,
                'role-catalog-member',
            ),
        ]);

        DB::table('memberships')->insert([
            $this->membershipData(
                $this->adminMembershipId,
                $this->adminPersonId,
            ),
            $this->membershipData(
                $this->regularMembershipId,
                $this->regularPersonId,
            ),
        ]);

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
                'id' => $this->customRoleId,
                'name' => 'custom-operator',
                'display_name' => 'Custom Operator',
                'description' => 'Custom global role.',
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);

        $permissionId = UuidV7::generate();

        DB::table('permissions')->insert([
            'id' => $permissionId,
            'name' => 'catalog.test.permission',
            'display_name' => 'Catalog Test Permission',
            'description' => 'Permission used only to verify response isolation.',
            'module' => 'Core',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('role_permissions')->insert([
            'role_id' => $this->adminRoleId,
            'permission_id' => $permissionId,
        ]);

        DB::table('membership_roles')->insert([
            'membership_id' => $this->adminMembershipId,
            'role_id' => $this->adminRoleId,
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

    /**
     * @return array<string, mixed>
     */
    private function membershipData(
        string $id,
        string $personId,
    ): array {
        return [
            'id' => $id,
            'person_id' => $personId,
            'tenant_id' => $this->tenantId,
            'status' => 'ACTIVE',
            'created_at' => now(),
            'updated_at' => now(),
        ];
    }

    private function issueToken(
        string $userId,
        string $membershipId,
    ): string {
        return app(TokenManagerInterface::class)
            ->issueToken(
                $userId,
                $this->tenantId,
                ['membership_id' => $membershipId],
            );
    }
}
