<?php

namespace Modules\Academic\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Core\Tests\TestCase;

class StudentManagementTest extends TestCase
{
    use RefreshDatabase;

    public function test_can_create_student_within_tenant_context(): void
    {
        $tenantId = (string) Str::uuid();
        $classId = (string) Str::uuid();
        $studentId = (string) Str::uuid();

        // 1. Seed Tenant
        DB::table('tenants')->insert([
            'id' => $tenantId,
            'name' => 'Pesantren Uji Core',
            'subdomain' => 'pesantren-core-' . Str::random(5),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // 2. Seed Class
        DB::table('academic_classes')->insert([
            'id' => $classId,
            'tenant_id' => $tenantId,
            'name' => 'Kelas VIII Uji',
            'code' => 'K8U',
            'tingkat' => 8,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // 3. Insert Santri (Sesuai Skema Fisik DB: Tanpa membership_id & nisn)
        DB::table('students')->insert([
            'id' => $studentId,
            'tenant_id' => $tenantId,
            'class_id' => $classId,
            'nis' => '20269999',
            'name' => 'Santri Test Core',
            'gender' => 'L',
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // 4. Assert Database Has Data
        $this->assertDatabaseHas('students', [
            'id' => $studentId,
            'tenant_id' => $tenantId,
            'nis' => '20269999',
        ]);
    }
}
