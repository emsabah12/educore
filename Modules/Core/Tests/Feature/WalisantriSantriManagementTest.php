<?php

declare(strict_types=1);

namespace Modules\Core\Tests\Feature;

use Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Modules\Core\Support\Uuid\UuidV7;

final class WalisantriSantriManagementTest extends TestCase
{
    use RefreshDatabase;

    private string $tenantId;
    private string $classId;
    private string $santriId;
    private string $waliId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenantId = '019f62f3-f5b5-7216-9578-0af9cb3b5b54';
        $this->classId = UuidV7::generate();
        $this->santriId = UuidV7::generate();
        $this->waliId = UuidV7::generate();

        // 1. Seed Tenant
        DB::table('tenants')->insert(['id' => $this->tenantId, 'name' => 'Pesantren Pivot', 'subdomain' => 'test-pivot', 'is_active' => true]);

        // 2. Seed Kelas
        DB::table('academic_classes')->insert(['id' => $this->classId, 'tenant_id' => $this->tenantId, 'name' => 'Kelas IX X', 'tingkat' => '9', 'is_active' => true]);

        // 3. Seed Santri Lintas 3 Tabel Dasar
        $userSantriId = UuidV7::generate();
        $memSantriId = UuidV7::generate();
        DB::table('users')->insert(['id' => $userSantriId, 'name' => 'Santri Anak', 'email' => 'anak@educore.id', 'password' => 'secret', 'status' => 'ACTIVE']);
        DB::table('memberships')->insert(['id' => $memSantriId, 'user_id' => $userSantriId, 'tenant_id' => $this->tenantId, 'role' => 'SANTRI', 'status' => 'ACTIVE']);
        DB::table('santris')->insert(['id' => $this->santriId, 'tenant_id' => $this->tenantId, 'membership_id' => $memSantriId, 'class_id' => $this->classId, 'nis' => '1111']);

        // 4. Seed Wali Lintas 3 Tabel Dasar
        $userWaliId = UuidV7::generate();
        $memWaliId = UuidV7::generate();
        DB::table('users')->insert(['id' => $userWaliId, 'name' => 'Wali Bapak', 'email' => 'bapak@educore.id', 'password' => 'secret', 'status' => 'ACTIVE']);
        DB::table('memberships')->insert(['id' => $memWaliId, 'user_id' => $userWaliId, 'tenant_id' => $this->tenantId, 'role' => 'WALISANTRI', 'status' => 'ACTIVE']);
        DB::table('walisantris')->insert(['id' => $this->waliId, 'tenant_id' => $this->tenantId, 'membership_id' => $memWaliId, 'no_hp' => '0812']);
    }

    public function test_can_bind_and_unbind_many_to_many_relationships_safely(): void
    {
        $repo = app(\Modules\Core\Contracts\Repository\WalisantriSantriRepositoryInterface::class);

        // Uji fungsionalitas penautan data (Attach)
        $attached = $repo->attachSantri($this->tenantId, $this->waliId, $this->santriId, 'IBU KANDUNG');
        $this->assertTrue($attached);
        $this->assertDatabaseHas('walisantri_santri', ['walisantri_id' => $this->waliId, 'santri_id' => $this->santriId]);

        // Uji kueri penarikan relasi
        $children = $repo->getSantriByWalisantri($this->tenantId, $this->waliId);
        $this->assertCount(1, $children);
        $this->assertEquals('Santri Anak', $children[0]->nama_santri);

        // Uji fungsionalitas pemutusan hubungan (Detach)
        $detached = $repo->detachSantri($this->tenantId, $this->waliId, $this->santriId);
        $this->assertTrue($detached);
        $this->assertDatabaseMissing('walisantri_santri', ['walisantri_id' => $this->waliId, 'santri_id' => $this->santriId]);
    }
}
