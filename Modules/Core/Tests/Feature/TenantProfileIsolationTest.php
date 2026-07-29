<?php

declare(strict_types=1);

namespace Modules\Core\Tests\Feature;

use Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\HR\Models\Employee;
use Exception;

final class TenantProfileIsolationTest extends TestCase
{
    // Memastikan database testing di-refresh total dengan skema bersih sebelum tes dijalankan
    use RefreshDatabase;

    private string $tenantA;
    private string $tenantB;

    /**
     * Set up state awal data pengujian sebelum setiap metode tes dijalankan.
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->tenantA = Str::uuid()->toString();
        $this->tenantB = Str::uuid()->toString();

        // Menyediakan data master tenant untuk mematuhi integritas Foreign Key
        DB::table('tenants')->insert([
            ['id' => $this->tenantA, 'name' => 'Sekolah Pusat A', 'subdomain' => 'pusata', 'is_active' => true, 'created_at' => now(), 'updated_at' => now()],
            ['id' => $this->tenantB, 'name' => 'Sekolah Cabang B', 'subdomain' => 'cabangb', 'is_active' => true, 'created_at' => now(), 'updated_at' => now()],
        ]);
    }

    /**
     * Uji 1: Memastikan Trait pengisolasi data menyaring query profil employee antar tenant secara otomatis.
     */
    public function test_it_strictly_isolates_employee_profiles_between_tenants(): void
    {
        // 1. Buat User Global & Membership untuk Tenant A
        $userIdA = Str::uuid()->toString();
        $membershipIdA = Str::uuid()->toString();

        DB::table('users')->insert([
            'id' => $userIdA,
            'name' => 'User A',
            'email' => 'user_a@educore.id',
            'password' => 'secret',
            'status' => 'ACTIVE',
            'created_at' => now(),
            'updated_at' => now()
        ]);

        DB::table('memberships')->insert([
            'id' => $membershipIdA,
            'user_id' => $userIdA,
            'tenant_id' => $this->tenantA,
            'role' => 'employee',
            'status' => 'ACTIVE',
            'created_at' => now(),
            'updated_at' => now()
        ]);

        // Kunci context ke Tenant A, lalu simpan data employee A
        app()->singleton('current_tenant_uuid', fn() => $this->tenantA);

        $employeeA = new Employee();
        $employeeA->id = Str::uuid()->toString();
        $employeeA->membership_id = $membershipIdA;
        $employeeA->nip = '19900101AA';
        $employeeA->jabatan = 'GURU';
        $employeeA->save();

        // 2. Buat User Global & Membership untuk Tenant B
        $userIdB = Str::uuid()->toString();
        $membershipIdB = Str::uuid()->toString();

        DB::table('users')->insert([
            'id' => $userIdB,
            'name' => 'User B',
            'email' => 'user_b@educore.id',
            'password' => 'secret',
            'status' => 'ACTIVE',
            'created_at' => now(),
            'updated_at' => now()
        ]);

        DB::table('memberships')->insert([
            'id' => $membershipIdB,
            'user_id' => $userIdB,
            'tenant_id' => $this->tenantB,
            'role' => 'employee',
            'status' => 'ACTIVE',
            'created_at' => now(),
            'updated_at' => now()
        ]);

        // Alihkan context ke Tenant B, lalu simpan data employee B
        app()->singleton('current_tenant_uuid', fn() => $this->tenantB);

        $employeeB = new Employee();
        $employeeB->id = Str::uuid()->toString();
        $employeeB->membership_id = $membershipIdB;
        $employeeB->nip = '19950202BB';
        $employeeB->jabatan = 'STAFF';
        $employeeB->save();

        // 3. VERIFIKASI ASPEK KEAMANAN QUERY ISOLATION (Data Leak Prevention)
        // Sesi saat ini terkunci di Tenant B. Panggilan employee::all() HANYA boleh mengembalikan data milik Tenant B!
        $hasilQueryDiTenantB = Employee::all();

        $this->assertCount(1, $hasilQueryDiTenantB);
        $this->assertEquals('19950202BB', $hasilQueryDiTenantB->first()->nip);

        // Alihkan kembali ke Tenant A
        app()->singleton('current_tenant_uuid', fn() => $this->tenantA);

        $hasilQueryDiTenantA = Employee::all();

        $this->assertCount(1, $hasilQueryDiTenantA);
        $this->assertEquals('19900101AA', $hasilQueryDiTenantA->first()->nip);
    }

    /**
     * Uji 2: Memastikan integritas Cascading Delete berjalan di PostgreSQL level.
     */
    public function test_it_cascades_delete_on_profile_when_membership_is_removed(): void
    {
        $userId = Str::uuid()->toString();
        $membershipId = Str::uuid()->toString();

        DB::table('users')->insert([
            'id' => $userId,
            'name' => 'Employee Test',
            'email' => 'employee_test@educore.id',
            'password' => 'secret',
            'status' => 'ACTIVE',
            'created_at' => now(),
            'updated_at' => now()
        ]);

        DB::table('memberships')->insert([
            'id' => $membershipId,
            'user_id' => $userId,
            'tenant_id' => $this->tenantA,
            'role' => 'employee',
            'status' => 'ACTIVE',
            'created_at' => now(),
            'updated_at' => now()
        ]);

        app()->singleton('current_tenant_uuid', fn() => $this->tenantA);

        $employee = new Employee();
        $employee->id = Str::uuid()->toString();
        $employee->membership_id = $membershipId;
        $employee->nip = '20269999';
        $employee->jabatan = 'GURU';
        $employee->save();

        // Pastikan record tersimpan dengan aman
        $this->assertDatabaseHas('employees', ['id' => $employee->id]);

        // AKSI: Hapus baris data di tabel induk (memberships)
        DB::table('memberships')->where('id', '=', $membershipId)->delete();

        // ASSERTION: Tabel anak (employees) HARUS ikut terhapus secara otomatis oleh PostgreSQL cascade guard
        $this->assertDatabaseMissing('employees', ['id' => $employee->id]);
    }
}
