<?php

declare(strict_types=1);

namespace Modules\Core\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Core\Authorization\Database\Seeders\AuthorizationCatalogSeeder;
use Modules\Core\Identity\Models\User;
use Modules\Core\Tenancy\Models\Tenant;
use Tests\TestCase;

final class PlatformTenantControllerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(AuthorizationCatalogSeeder::class);
    }

    public function test_index_lists_tenants_for_superadmin(): void
    {
        $superadmin = User::factory()->create([
            'is_superadmin' => true,
        ]);

        Tenant::query()->create([
            'name' => 'Sekolah Contoh',
            'subdomain' => 'sekolah-contoh-' . uniqid(),
            'is_active' => true,
        ]);

        $this->actingAs($superadmin, 'web')
            ->get(route('platform.tenants.index'))
            ->assertOk()
            ->assertSee('Sekolah Contoh');
    }

    public function test_index_is_forbidden_for_non_superadmin(): void
    {
        $regularUser = User::factory()->create([
            'is_superadmin' => false,
        ]);

        $this->actingAs($regularUser, 'web')
            ->get(route('platform.tenants.index'))
            ->assertForbidden();
    }

    public function test_create_form_is_visible_to_superadmin(): void
    {
        $superadmin = User::factory()->create([
            'is_superadmin' => true,
        ]);

        $this->actingAs($superadmin, 'web')
            ->get(route('platform.tenants.create'))
            ->assertOk()
            ->assertSee('Daftarkan Tenant Baru');
    }

    public function test_store_creates_tenant_with_new_admin(): void
    {
        $superadmin = User::factory()->create([
            'is_superadmin' => true,
        ]);

        $response = $this
            ->actingAs($superadmin, 'web')
            ->post(route('platform.tenants.store'), [
                'name' => 'SMA Panel Baru',
                'subdomain' => 'sma-panel-baru',
                'admin_name' => 'Admin Panel Baru',
                'admin_email' => 'admin.panel.baru@educore.test',
                'admin_password' => 'kata-sandi-kuat-123',
            ]);

        $response->assertRedirect(route('platform.tenants.index'));
        $response->assertSessionHas('status');

        $this->assertDatabaseHas('tenants', [
            'subdomain' => 'sma-panel-baru',
        ]);

        $this->assertDatabaseHas('users', [
            'email' => 'admin.panel.baru@educore.test',
        ]);
    }

    public function test_store_redirects_back_with_errors_on_duplicate_subdomain(): void
    {
        $superadmin = User::factory()->create([
            'is_superadmin' => true,
        ]);

        Tenant::query()->create([
            'name' => 'Tenant Lama',
            'subdomain' => 'sudah-ada',
            'is_active' => true,
        ]);

        $response = $this
            ->actingAs($superadmin, 'web')
            ->from(route('platform.tenants.create'))
            ->post(route('platform.tenants.store'), [
                'name' => 'Tenant Baru',
                'subdomain' => 'sudah-ada',
                'admin_name' => 'Admin Baru',
                'admin_email' => 'admin.baru.unik@educore.test',
                'admin_password' => 'kata-sandi-kuat-123',
            ]);

        $response->assertRedirect(route('platform.tenants.create'));
        $response->assertSessionHasErrors(['subdomain']);
    }

    public function test_store_is_forbidden_for_non_superadmin(): void
    {
        $regularUser = User::factory()->create([
            'is_superadmin' => false,
        ]);

        $this
            ->actingAs($regularUser, 'web')
            ->post(route('platform.tenants.store'), [
                'name' => 'Tenant Tidak Sah',
                'subdomain' => 'tenant-tidak-sah',
                'admin_name' => 'Admin Tidak Sah',
                'admin_email' => 'tidak.sah@educore.test',
                'admin_password' => 'kata-sandi-kuat-123',
            ])
            ->assertForbidden();

        $this->assertDatabaseMissing('tenants', [
            'subdomain' => 'tenant-tidak-sah',
        ]);
    }
}
