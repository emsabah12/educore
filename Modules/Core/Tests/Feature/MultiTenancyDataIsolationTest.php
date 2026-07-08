<?php

namespace Modules\Core\Tests\Feature;

use Tests\TestCase;
use Modules\Core\Entities\Tenant;
use Modules\Core\Contracts\TenantContextInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;

class MultiTenancyDataIsolationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        
        // Buat tabel users bayangan / pastikan skema users siap pakai
        $this->assertTrue(Schema::hasTable('users'));
    }

    public function testDataIsStrictlyIsolatedBetweenTenants(): void
    {
        // 1. Arrange: Buat 2 Tenant Berbeda
        $tenantA = Tenant::create(['name' => 'Sekolah A', 'subdomain' => 'sekolaha']);
        $tenantB = Tenant::create(['name' => 'Sekolah B', 'subdomain' => 'sekolahb']);

        $context = $this->app->make(TenantContextInterface::class);

        // --- SIMULASI REQ TENANT A ---
        $context->setCurrentTenant($tenantA);
        
        // Buat user di Tenant A menggunakan model user bawaan laravel yang nanti kita pasangkan Trait
        // Untuk keperluan test ini, kita buat dummy user via Query Builder / Model
        $userA = \App\Models\User::create([
            'name' => 'Admin Sekolah A',
            'email' => 'admin@sekolaha.com',
            'password' => bcrypt('secret123')
        ]);
        
        // Pastikan userA otomatis mendapat tenant_id milik tenantA
        $this->assertEquals($tenantA->id, $userA->tenant_id);

        // --- SIMULASI REQ TENANT B ---
        $context->setCurrentTenant($tenantB);
        
        $userB = \App\Models\User::create([
            'name' => 'Admin Sekolah B',
            'email' => 'admin@sekolahb.com',
            'password' => bcrypt('secret123')
        ]);
        
        // Pastikan userB otomatis mendapat tenant_id milik tenantB
        $this->assertEquals($tenantB->id, $userB->tenant_id);

        // 2. Act & Assert: Validasi Proteksi Kebocoran Data (Data Leakage)
        
        // Saat Context berada di Tenant B, query mencari User milik Tenant A harus menghasilkan NULL/Kosong
        $searchUserA = \App\Models\User::where('email', 'admin@sekolaha.com')->first();
        $this->assertNull($searchUserA, 'Kritis! Data Tenant A bocor dan dapat dibaca dari Tenant B.');

        // Total user yang dibaca oleh Tenant B harus bernilai 1 (hanya userB), bukan 2.
        $this->assertEquals(1, \App\Models\User::count());

        // --- PINDAH KEMBALI KE CONTEXT TENANT A ---
        $context->setCurrentTenant($tenantA);
        $this->assertEquals(1, \App\Models\User::count());
        $this->assertNotNull(\App\Models\User::where('email', 'admin@sekolaha.com')->first());
    }
}