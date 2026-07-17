<?php

declare(strict_types=1);

namespace Modules\Core\Tests\Feature;

use Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Modules\Core\Support\Uuid\UuidV7;

final class WalisantriManagementTest extends TestCase
{
    use RefreshDatabase;

    private string $tenantId;

    /**
     * Menyiapkan data awal (fixtures) sebelum pengetesan berjalan.
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->tenantId = '019f62f3-f5b5-7216-9578-0af9cb3b5b54';

        // Seed Master Tenant untuk kebutuhan integritas foreign key
        DB::table('tenants')->insert([
            'id' => $this->tenantId,
            'name' => 'Pesantren Uji Wali',
            'subdomain' => 'test-wali',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now()
        ]);
    }

    /**
     * Memastikan pendaftaran wali santri baru via repository terisolasi berjalan deterministik.
     */
    public function test_can_create_walisantri_within_tenant_context(): void
    {
        $payload = [
            'nama'  => 'H. Ahmad Sulaiman',
            'email' => 'ahmad.sulaiman@educore.id',
            'no_hp' => '081234567899'
        ];

        // Jalankan robust integration via repository engine secara langsung
        $repo = app(\Modules\Core\Contracts\Repository\WalisantriRepositoryInterface::class);
        $walisantri = $repo->createForTenant($this->tenantId, $payload);

        // Verifikasi kebenaran mutasi data biner
        $this->assertEquals($payload['nama'], $walisantri['nama']);
        $this->assertEquals($payload['no_hp'], $walisantri['no_hp']);

        $this->assertDatabaseHas('users', ['email' => $payload['email']]);
        $this->assertDatabaseHas('walisantris', ['no_hp' => '081234567899', 'tenant_id' => $this->tenantId]);
    }
}
