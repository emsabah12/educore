<?php

declare(strict_types=1);

namespace Modules\Core\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Modules\Core\Identity\Models\User;
use Modules\Core\Support\Uuid\UuidV7;
use Modules\Core\Tenancy\Models\Tenant;
use Tests\TestCase;

final class PlatformAuditLogControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_index_lists_logs_across_all_tenants(): void
    {
        $superadmin = User::factory()->create([
            'is_superadmin' => true,
        ]);

        $tenantA = Tenant::query()->create([
            'name' => 'Tenant Log A',
            'subdomain' => 'tenant-log-a',
            'is_active' => true,
        ]);

        $tenantB = Tenant::query()->create([
            'name' => 'Tenant Log B',
            'subdomain' => 'tenant-log-b',
            'is_active' => true,
        ]);

        $this->insertAuditLog($tenantA->id, 'tenant.activated', 'Peristiwa di Tenant Log A');
        $this->insertAuditLog($tenantB->id, 'tenant.deactivated', 'Peristiwa di Tenant Log B');

        $this->actingAs($superadmin, 'web')
            ->get(route('platform.audit-logs.index'))
            ->assertOk()
            ->assertSee('Peristiwa di Tenant Log A')
            ->assertSee('Peristiwa di Tenant Log B')
            ->assertSee('Tenant Log A')
            ->assertSee('Tenant Log B');
    }

    public function test_index_filters_by_event_type(): void
    {
        $superadmin = User::factory()->create([
            'is_superadmin' => true,
        ]);

        $tenant = Tenant::query()->create([
            'name' => 'Tenant Filter Uji',
            'subdomain' => 'tenant-filter-uji',
            'is_active' => true,
        ]);

        $this->insertAuditLog($tenant->id, 'tenant.activated', 'Kejadian Diaktifkan');
        $this->insertAuditLog($tenant->id, 'tenant.deactivated', 'Kejadian Dinonaktifkan');

        $this->actingAs($superadmin, 'web')
            ->get(route('platform.audit-logs.index', ['event_type' => 'tenant.activated']))
            ->assertOk()
            ->assertSee('Kejadian Diaktifkan')
            ->assertDontSee('Kejadian Dinonaktifkan');
    }

    public function test_index_filters_by_tenant(): void
    {
        $superadmin = User::factory()->create([
            'is_superadmin' => true,
        ]);

        $tenantA = Tenant::query()->create([
            'name' => 'Tenant Saring A',
            'subdomain' => 'tenant-saring-a',
            'is_active' => true,
        ]);

        $tenantB = Tenant::query()->create([
            'name' => 'Tenant Saring B',
            'subdomain' => 'tenant-saring-b',
            'is_active' => true,
        ]);

        $this->insertAuditLog($tenantA->id, 'tenant.activated', 'Hanya Tenant A');
        $this->insertAuditLog($tenantB->id, 'tenant.activated', 'Hanya Tenant B');

        $this->actingAs($superadmin, 'web')
            ->get(route('platform.audit-logs.index', ['tenant_id' => $tenantA->id]))
            ->assertOk()
            ->assertSee('Hanya Tenant A')
            ->assertDontSee('Hanya Tenant B');
    }

    public function test_index_survives_hard_deleted_tenant_reference(): void
    {
        $superadmin = User::factory()->create([
            'is_superadmin' => true,
        ]);

        $tenant = Tenant::query()->create([
            'name' => 'Tenant Akan Dihapus',
            'subdomain' => 'tenant-akan-dihapus',
            'is_active' => true,
        ]);

        $this->insertAuditLog($tenant->id, 'tenant.activated', 'Riwayat Sebelum Dihapus');

        $tenant->forceDelete();

        // `tenant_id` di audit_logs nullOnDelete() -- baris riwayat
        // harus tetap muncul walau tenant-nya sudah tidak ada lagi.
        $this->actingAs($superadmin, 'web')
            ->get(route('platform.audit-logs.index'))
            ->assertOk()
            ->assertSee('Riwayat Sebelum Dihapus');
    }

    public function test_index_is_forbidden_for_non_superadmin(): void
    {
        $regularUser = User::factory()->create([
            'is_superadmin' => false,
        ]);

        $this->actingAs($regularUser, 'web')
            ->get(route('platform.audit-logs.index'))
            ->assertForbidden();
    }

    private function insertAuditLog(string $tenantId, string $eventType, string $description): void
    {
        DB::table('audit_logs')->insert([
            'id' => UuidV7::generate(),
            'tenant_id' => $tenantId,
            'actor_user_id' => null,
            'event_type' => $eventType,
            'description' => $description,
            'metadata' => null,
            'ip_address' => null,
            'user_agent' => null,
            'created_at' => now(),
        ]);
    }
}
