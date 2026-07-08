<?php

namespace Modules\Core\Tests\Unit;

use Tests\TestCase;
use Modules\Core\Entities\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;

class TenantEntityTest extends TestCase
{
    use RefreshDatabase;
    /**
     * Memastikan UUID v7 digenerate otomatis melalui model event 'creating'.
     */
    public function testAutomaticallyGeneratesUuidV7OnInstantiation(): void
    {
        // 1. Arrange data instansiasi objek
        $tenant = new Tenant([
            'name' => 'SDN Merdeka 01',
            'subdomain' => 'sdnmerdeka01',
            'domain' => 'sdnmerdeka01.sch.id',
            'is_active' => true,
            'settings' => ['theme' => 'blue']
        ]);

        // 2. Act
        // Menggunakan Reflection untuk memanggil 'fireModelEvent' yang berstatus protected
        $reflection = new \ReflectionClass($tenant);
        $method = $reflection->getMethod('fireModelEvent');
        $method->setAccessible(true);
        
        // Picu event 'creating' secara aman via reflection tanpa menyimpan ke DB fisik
        $method->invokeArgs($tenant, ['creating', false]);

        // 3. Assert objek memori
        $this->assertNotEmpty($tenant->id, 'UUID v7 tidak boleh kosong.');
        $this->assertIsString($tenant->id, 'UUID harus berupa tipe data string.');
        $this->assertEquals(36, strlen($tenant->id), 'Panjang UUID v7 standar harus 36 karakter.');
        
        // Memastikan karakter ke-15 (index ke-14) adalah angka '7' sebagai penanda UUID v7
        $this->assertEquals('7', $tenant->id[14], 'Versi UUID yang digenerate bukan versi 7.');
    }

    public function testTenantCanBeSavedToDatabaseWithUuidV7(): void
    {
        // Gunakan fungsi bawaan untuk refresh database di dalam test ini jika diperlukan
        // (Karena kelas ini turunan TestCase, pastikan database testing terkonfigurasi di phpunit.xml)

        $tenant = \Modules\Core\Entities\Tenant::create([
            'name' => 'SMP Digital Indonesia',
            'subdomain' => 'smpdigital',
            'domain' => 'smpdigital.sch.id',
            'is_active' => true,
            'settings' => ['max_users' => 100]
        ]);

        // Pastikan data berhasil masuk ke DB fisik dan ID terisi otomatis via event
        $this->assertDatabaseHas('tenants', [
            'id' => $tenant->id,
            'subdomain' => 'smpdigital'
        ]);
    }
}