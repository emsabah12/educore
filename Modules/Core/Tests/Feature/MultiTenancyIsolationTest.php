<?php

declare(strict_types=1);

namespace Modules\Core\Tests\Feature;

use Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Modules\Auth\Entities\MockStudent;
use Illuminate\Support\Str;
use Exception;

final class MultiTenancyIsolationTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Uji 1: Sistem wajib memblokir pembuatan data bisnis jika tidak ada konteks tenant aktif.
     */
    public function test_it_blocks_creation_without_tenant_context(): void
    {
        app()->offsetUnset('current_tenant_uuid');

        $this->expectException(Exception::class);
        $this->expectExceptionMessage('Bypass Blocked: Cannot write tenant data without an authenticated tenant context.');

        $student = new MockStudent();
        $student->id = Str::uuid()->toString();
        $student->name = 'Santri Tanpa Lembaga';
        $student->nisn = '1234567890';
        $student->status = 'ACTIVE';
        $student->save();
    }

    /**
     * Uji 2: Sistem harus secara otomatis menginjeksi tenant_id dan mengisolasi query antar tenant.
     */
    public function test_it_automatically_injects_tenant_id_and_scopes_queries(): void
    {
        $tenantA = Str::uuid()->toString();
        $tenantB = Str::uuid()->toString();

        // FIX: DAFTARKAN TENANT KE DATABASE TERLEBIH DAHULU
        DB::table('tenants')->insert([
            ['id' => $tenantA, 'name' => 'Lembaga A', 'subdomain' => 'a', 'is_active' => true, 'created_at' => now(), 'updated_at' => now()],
            ['id' => $tenantB, 'name' => 'Lembaga B', 'subdomain' => 'b', 'is_active' => true, 'created_at' => now(), 'updated_at' => now()],
        ]);

        // --- STEP 1: Masuk Konteks Tenant A ---
        app()->singleton('current_tenant_uuid', fn() => $tenantA);

        $studentA = new MockStudent();
        $studentA->id = Str::uuid()->toString();
        $studentA->name = 'Siswa Lembaga A';
        $studentA->nisn = '0000000001';
        $studentA->status = 'ACTIVE';
        $studentA->save();

        $this->assertEquals($tenantA, $studentA->tenant_id);

        // --- STEP 2: Masuk Konteks Tenant B ---
        app()->singleton('current_tenant_uuid', fn() => $tenantB);

        $studentB = new MockStudent();
        $studentB->id = Str::uuid()->toString();
        $studentB->name = 'Siswa Lembaga B';
        $studentB->nisn = '0000000002';
        $studentB->status = 'ACTIVE';
        $studentB->save();

        // --- STEP 3: Verifikasi Isolasi Data ---
        $studentsInTenantB = MockStudent::all();
        $this->assertCount(1, $studentsInTenantB);
        $this->assertEquals('Siswa Lembaga B', $studentsInTenantB->first()->name);

        // --- STEP 4: Kembali ke Tenant A ---
        app()->singleton('current_tenant_uuid', fn() => $tenantA);

        $studentsInTenantA = MockStudent::all();
        $this->assertCount(1, $studentsInTenantA);
        $this->assertEquals('Siswa Lembaga A', $studentsInTenantA->first()->name);
    }
}
