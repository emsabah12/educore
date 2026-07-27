<?php

declare(strict_types=1);

namespace Modules\Core\Tests\Feature;

use Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Modules\Core\Support\Uuid\UuidV7;

final class TenantAwareAuthenticationTest extends TestCase
{
    // Memastikan skema database testing di-refresh total sebelum mengeksekusi test
    use RefreshDatabase;

    private string $tenantA;
    private string $tenantB;
    private string $userGlobalId;
    private string $email = 'superadmin@educore.id';
    private string $password = 'secretpassword';

    /**
     * Mengatur data awal (fixtures) sebelum setiap metode tes dijalankan.
     */
    protected function setUp(): void
    {
        parent::setUp();

        // 1. Generate ID untuk dua Tenant berbeda menggunakan format UuidV7
        $this->tenantA = '019f62f3-f5b5-7216-9578-0af9cb3b5b54';
        $this->tenantB = '019f62f3-f5b5-7216-9578-0af9cb3b5b55';

        DB::table('tenants')->insert([
            [
                'id' => $this->tenantA,
                'name' => 'Sekolah Pusat A',
                'subdomain' => 'sekolaha',
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now()
            ],
            [
                'id' => $this->tenantB,
                'name' => 'Sekolah Cabang B',
                'subdomain' => 'sekolahb',
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now()
            ]
        ]);

        // 2. Generate Akun User Global murni terenkripsi standar
        $this->userGlobalId = UuidV7::generate();
        DB::table('users')->insert([
            'id' => $this->userGlobalId,
            'name' => 'User Global EduCore',
            'email' => $this->email,
            'password' => Hash::make($this->password),
            'status' => 'ACTIVE',
            'created_at' => now(),
            'updated_at' => now()
        ]);

        // 3. Daftarkan user HANYA pada Tenant A melalui tabel memberships
        DB::table('memberships')->insert([
            'id' => UuidV7::generate(),
            'user_id' => $this->userGlobalId,
            'tenant_id' => $this->tenantA,
            'role' => 'employee',
            'status' => 'ACTIVE',
            'created_at' => now(),
            'updated_at' => now()
        ]);
    }

    /**
     * Uji Kasus Sukses: User harus berhasil mendapatkan token jika masuk ke tenant yang valid (Tenant A).
     */
    public function test_user_can_login_to_assigned_tenant_with_valid_credentials(): void
    {
        $payload = [
            'email' => $this->email,
            'password' => $this->password,
            'tenant_uuid' => $this->tenantA
        ];

        // Memanfaatkan simulasi HTTP Kernel Request Laravel yang valid
        $response = $this->postJson('/api/v1/auth/login-token', $payload);

        $response->assertStatus(200);

        $responseData = $response->json();
        $this->assertEquals('success', $responseData['status']);
        $this->assertArrayHasKey('access_token', $responseData['data']);
        $this->assertArrayNotHasKey(
            'role',
            $responseData['data']['context']
        );
    }

    /**
     * Uji Kasus Gagal (Cross-Tenant Block): User ditolak masuk jika menembak Tenant B.
     */
    public function test_user_cannot_login_to_different_tenant_with_valid_credentials(): void
    {
        $payload = [
            'email' => $this->email,
            'password' => $this->password,
            'tenant_uuid' => $this->tenantB // Mencoba masuk ke lembaga yang tidak terdaftar
        ];

        $response = $this->postJson('/api/v1/auth/login-token', $payload);

        $response->assertStatus(401);

        $responseData = $response->json();
        $this->assertEquals('error', $responseData['status']);
        $this->assertEquals('Unauthorized. Invalid identity credentials.', $responseData['message']);
    }
}
