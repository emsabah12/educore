<?php

namespace Modules\Core\Tests\Feature;

use Tests\TestCase;
use Modules\Core\Entities\Tenant;
use Modules\Core\Contracts\TenantContextInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;

class TenantAwareAuthenticationTest extends TestCase
{
    use RefreshDatabase;

    public function testUserCannotLoginToDifferentTenantWithValidCredentials(): void
    {
        // 1. Arrange: Buat Tenant A dan Tenant B
        $tenantA = Tenant::create(['name' => 'Sekolah A', 'subdomain' => 'sekolaha']);
        $tenantB = Tenant::create(['name' => 'Sekolah B', 'subdomain' => 'sekolahb']);

        $context = $this->app->make(TenantContextInterface::class);

        // Daftarkan 'admin@sekolah.com' di Tenant A
        $context->setCurrentTenant($tenantA);
        $userA = \App\Models\User::create([
            'name' => 'Admin A',
            'email' => 'admin@sekolah.com',
            'password' => bcrypt('password123')
        ]);

        // 2. Act: Coba login ke Tenant B menggunakan kredensial milik Tenant A tadi
        $context->setCurrentTenant($tenantB);

        $attempt = Auth::attempt([
            'email' => 'admin@sekolah.com',
            'password' => 'password123'
        ]);

        // 3. Assert: Login harus GAGAL (false) karena user tersebut terdaftar di Tenant A
        $this->assertFalse($attempt, 'Kritis! User dari Tenant A berhasil menembus login Tenant B.');
        $this->assertFalse(Auth::check(), 'Kritis! Auth session terbuat pada tenant yang salah.');

        // --- COBA LOGIN DI TENANT YANG BENAR (TENANT A) ---
        $context->setCurrentTenant($tenantA);

        $successfulAttempt = Auth::attempt([
            'email' => 'admin@sekolah.com',
            'password' => 'password123'
        ]);

        $this->assertTrue($successfulAttempt);
        $this->assertTrue(Auth::check());
        $this->assertEquals($userA->id, Auth::id());
    }
}