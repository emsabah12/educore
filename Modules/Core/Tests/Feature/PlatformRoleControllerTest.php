<?php

declare(strict_types=1);

namespace Modules\Core\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Core\Authorization\Models\Permission;
use Modules\Core\Authorization\Models\Role;
use Modules\Core\Identity\Models\User;
use Modules\Core\Support\Uuid\UuidV7;
use Tests\TestCase;

final class PlatformRoleControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_index_lists_roles_with_permission_counts(): void
    {
        $superadmin = User::factory()->create([
            'is_superadmin' => true,
        ]);

        $role = Role::query()->create([
            'name' => 'support-agent',
            'display_name' => 'Support Agent',
        ]);

        $permission = Permission::query()->create([
            'name' => 'platform.support.tickets.view',
            'display_name' => 'Lihat Tiket',
            'module' => 'Platform',
        ]);

        $role->permissions()->attach($permission->id);

        $this->actingAs($superadmin, 'web')
            ->get(route('platform.roles.index'))
            ->assertOk()
            ->assertSee('support-agent')
            ->assertSee('Support Agent')
            ->assertSee('1');
    }

    public function test_show_displays_permission_checkboxes_grouped_by_module(): void
    {
        $superadmin = User::factory()->create([
            'is_superadmin' => true,
        ]);

        $role = Role::query()->create([
            'name' => 'data-analyst',
            'display_name' => 'Data Analyst',
        ]);

        $permission = Permission::query()->create([
            'name' => 'platform.data.reports.view',
            'display_name' => 'Lihat Laporan',
            'module' => 'Platform',
        ]);

        $this->actingAs($superadmin, 'web')
            ->get(route('platform.roles.show', $role->id))
            ->assertOk()
            ->assertSee('Data Analyst')
            ->assertSee('platform.data.reports.view')
            ->assertSee('Platform');
    }

    public function test_update_syncs_role_permissions(): void
    {
        $superadmin = User::factory()->create([
            'is_superadmin' => true,
        ]);

        $role = Role::query()->create([
            'name' => 'finance-officer',
            'display_name' => 'Finance Officer',
        ]);

        $permissionA = Permission::query()->create([
            'name' => 'platform.finance.billing.view',
            'display_name' => 'Lihat Tagihan',
            'module' => 'Platform',
        ]);

        $permissionB = Permission::query()->create([
            'name' => 'platform.finance.billing.manage',
            'display_name' => 'Kelola Tagihan',
            'module' => 'Platform',
        ]);

        $response = $this
            ->actingAs($superadmin, 'web')
            ->put(route('platform.roles.update', $role->id), [
                'permission_ids' => [$permissionA->id, $permissionB->id],
            ]);

        $response->assertRedirect(route('platform.roles.show', $role->id));

        $this->assertDatabaseHas('role_permissions', [
            'role_id' => $role->id,
            'permission_id' => $permissionA->id,
        ]);

        $this->assertDatabaseHas('role_permissions', [
            'role_id' => $role->id,
            'permission_id' => $permissionB->id,
        ]);
    }

    public function test_update_removes_unchecked_permissions(): void
    {
        $superadmin = User::factory()->create([
            'is_superadmin' => true,
        ]);

        $role = Role::query()->create([
            'name' => 'support-agent-remove',
            'display_name' => 'Support Agent',
        ]);

        $permission = Permission::query()->create([
            'name' => 'platform.support.tickets.manage',
            'display_name' => 'Kelola Tiket',
            'module' => 'Platform',
        ]);

        $role->permissions()->attach($permission->id);

        $this
            ->actingAs($superadmin, 'web')
            ->put(route('platform.roles.update', $role->id), [
                'permission_ids' => [],
            ]);

        $this->assertDatabaseMissing('role_permissions', [
            'role_id' => $role->id,
            'permission_id' => $permission->id,
        ]);
    }

    public function test_update_does_not_accept_name_change(): void
    {
        $superadmin = User::factory()->create([
            'is_superadmin' => true,
        ]);

        $role = Role::query()->create([
            'name' => 'admin',
            'display_name' => 'Administrator',
        ]);

        $this
            ->actingAs($superadmin, 'web')
            ->put(route('platform.roles.update', $role->id), [
                'name' => 'renamed-admin',
                'permission_ids' => [],
            ]);

        $this->assertDatabaseHas('roles', [
            'id' => $role->id,
            'name' => 'admin',
        ]);
    }

    public function test_store_creates_new_custom_role(): void
    {
        $superadmin = User::factory()->create([
            'is_superadmin' => true,
        ]);

        $response = $this
            ->actingAs($superadmin, 'web')
            ->post(route('platform.roles.store'), [
                'name' => 'support-agent-new',
                'display_name' => 'Support Agent Baru',
                'description' => 'Tim dukungan pelanggan.',
            ]);

        $response->assertRedirect();

        $this->assertDatabaseHas('roles', [
            'name' => 'support-agent-new',
            'display_name' => 'Support Agent Baru',
        ]);
    }

    public function test_store_rejects_duplicate_role_name(): void
    {
        $superadmin = User::factory()->create([
            'is_superadmin' => true,
        ]);

        Role::query()->create([
            'name' => 'sudah-ada',
            'display_name' => 'Sudah Ada',
        ]);

        $this
            ->actingAs($superadmin, 'web')
            ->post(route('platform.roles.store'), [
                'name' => 'sudah-ada',
                'display_name' => 'Duplikat',
            ])
            ->assertSessionHasErrors(['name']);
    }

    public function test_index_is_forbidden_for_non_superadmin(): void
    {
        $regularUser = User::factory()->create([
            'is_superadmin' => false,
        ]);

        $this->actingAs($regularUser, 'web')
            ->get(route('platform.roles.index'))
            ->assertForbidden();
    }

    public function test_update_is_forbidden_for_non_superadmin(): void
    {
        $regularUser = User::factory()->create([
            'is_superadmin' => false,
        ]);

        $role = Role::query()->create([
            'name' => 'aman-uji',
            'display_name' => 'Aman Uji',
        ]);

        $this
            ->actingAs($regularUser, 'web')
            ->put(route('platform.roles.update', $role->id), [
                'permission_ids' => [],
            ])
            ->assertForbidden();
    }

    public function test_show_returns_not_found_for_unknown_role(): void
    {
        $superadmin = User::factory()->create([
            'is_superadmin' => true,
        ]);

        $this->actingAs($superadmin, 'web')
            ->get(route('platform.roles.show', UuidV7::generate()))
            ->assertNotFound();
    }
}
