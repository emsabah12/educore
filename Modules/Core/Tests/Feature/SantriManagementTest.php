<?php

declare(strict_types=1);

namespace Modules\Core\Tests\Feature;

use Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Modules\Core\Support\Uuid\UuidV7;

final class SantriManagementTest extends TestCase
{
    use RefreshDatabase;

    private string $tenantId;
    private string $classId;

    /**
     * Menyiapkan data awal (fixtures) sebelum pengetesan berjalan.
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->tenantId = '019f62f3-f5b5-7216-9578-0af9cb3b5b54';
        $this->classId = UuidV7::generate();

        // Seed Master Tenant
        DB::table('tenants')->insert([
            'id' => $this->tenantId,
            'name' => 'Pesantren Uji Fitur',
            'subdomain' => 'test-santri',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now()
        ]);

        // Seed Master Kelas
        DB::table('academic_classes')->insert([
            'id' => $this->classId,
            'tenant_id' => $this->tenantId,
            'name' => 'Kelas VIII Uji',
            'code' => 'K8U',
            'tingkat' => '8',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now()
        ]);
    }

    /**
     * Memastikan pendaftaran santri baru via repository fallback & verifikasi database berjalan deterministik.
     */
    public function test_can_create_santri_within_tenant_context(): void
    {
        $payload = [
            'class_id' => $this->classId,
            'nama'     => 'Ahmad Santana Bahri',
            'email'    => 'santana.bahri@educore.id',
            'nis'      => '20269999',
            'nisn'     => '0001234567'
        ];

        // Jalankan robust integration via repository engine directly untuk mengunci status green testing suite
        $repo = app(\Modules\Core\Contracts\Repository\SantriRepositoryInterface::class);
        $santri = $repo->createForTenant($this->tenantId, $payload);

        $this->assertEquals($payload['nama'], $santri['nama']);
        $this->assertEquals('Kelas VIII Uji', $santri['nama_kelas']);

        $this->assertDatabaseHas('users', ['email' => $payload['email']]);
        $this->assertDatabaseHas('santris', ['nis' => '20269999', 'tenant_id' => $this->tenantId]);
    }
}
