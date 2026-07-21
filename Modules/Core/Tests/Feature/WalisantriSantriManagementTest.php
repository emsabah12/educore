<?php

namespace Modules\Core\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Core\Tests\TestCase;

class WalisantriSantriManagementTest extends TestCase
{
    use RefreshDatabase;

    public function test_can_bind_and_unbind_many_to_many_relationships_safely(): void
    {
        $tenantId = (string) Str::uuid();
        $classId = (string) Str::uuid();
        $santriId = (string) Str::uuid();
        $walisantriId = (string) Str::uuid();

        // 1. Seed Tenant & Class Context
        DB::table('tenants')->insert([
            'id' => $tenantId,
            'name' => 'Pesantren Wali Test',
            'subdomain' => 'pesantren-wali-' . Str::random(5),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('academic_classes')->insert([
            'id' => $classId,
            'tenant_id' => $tenantId,
            'name' => 'Kelas IX X',
            'code' => 'K9X',
            'tingkat' => 9,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // 2. Insert Santri (Sesuai Skema Fisik Database PostgreSQL)
        DB::table('santris')->insert([
            'id' => $santriId,
            'tenant_id' => $tenantId,
            'class_id' => $classId,
            'nis' => '1111',
            'name' => 'Anak Santri',
            'gender' => 'L',
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // 3. Insert Walisantri (Persis Sesuai Kolom Fisik: nama_lengkap & phone, TANPA user_id)
        DB::table('walisantris')->insert([
            'id' => $walisantriId,
            'tenant_id' => $tenantId,
            'membership_id' => '',
            'no_hp' => '08123456789',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // 4. Bind Relationship (Sertakan Relasi Banyak-ke-Banyak di Pivot Table)
        DB::table('walisantri_santri')->insert([
            'walisantri_id' => $walisantriId,
            'santri_id' => $santriId,
            'relationship_type' => 'FATHER',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->assertDatabaseHas('walisantri_santri', [
            'walisantri_id' => $walisantriId,
            'santri_id' => $santriId,
        ]);

        // 5. Unbind Relationship (Hapus Relasi Secara Aman)
        DB::table('walisantri_santri')
            ->where('walisantri_id', $walisantriId)
            ->where('santri_id', $santriId)
            ->delete();

        $this->assertDatabaseMissing('walisantri_santri', [
            'walisantri_id' => $walisantriId,
            'santri_id' => $santriId,
        ]);
    }
}
