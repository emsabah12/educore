<?php

declare(strict_types=1);

namespace Modules\Academic\Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Modules\Academic\Http\Controllers\Api\v1\BulkGradingController;
use Tests\TestCase;

final class AcademicBulkGradingTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Mengatur lingkungan database testing modular dan rute sandbox.
     */
    protected function setUp(): void
    {
        parent::setUp();

        // Muat migrasi modul Academic secara eksplisit agar tabel academic_classes dll tersedia
        $this->loadMigrationsFrom(base_path('Modules/Academic/Database/Migrations'));

        Route::middleware(['api', \Illuminate\Session\Middleware\StartSession::class])->group(function () {
            Route::post('/api/v1/academic/grades/bulk', [BulkGradingController::class, 'storeBulk'])
                ->name('test.api.academic.grades.bulk');
        });
    }

    /**
     * Memastikan guru dapat memasukkan nilai santri secara massal dengan aman.
     */
    public function test_teacher_can_submit_student_grades_in_bulk(): void
    {
        $this->withoutExceptionHandling();

        $tenantId = (string) Str::uuid();
        $periodId = (string) Str::uuid();
        $subjectId = (string) Str::uuid();
        $teacherId = (string) Str::uuid();
        $classId = (string) Str::uuid();

        $userSantriAId = (string) Str::uuid();
        $userSantriBId = (string) Str::uuid();
        $membershipAId = (string) Str::uuid();
        $membershipBId = (string) Str::uuid();

        $santriAId = (string) Str::uuid();
        $santriBId = (string) Str::uuid();
        $settingId = (string) Str::uuid();

        // 1. Seed Parent Data: Users (Global, tanpa tenant_id) & Tenants
        DB::table('users')->insert([
            ['id' => $teacherId, 'name' => 'Ustadz Ahmad', 'email' => 'ahmad@educore.test', 'password' => 'secret', 'is_superadmin' => false],
            ['id' => $userSantriAId, 'name' => 'Ali User', 'email' => 'ali@educore.test', 'password' => 'secret', 'is_superadmin' => false],
            ['id' => $userSantriBId, 'name' => 'Budi User', 'email' => 'budi@educore.test', 'password' => 'secret', 'is_superadmin' => false],
        ]);

        DB::table('tenants')->insert(['id' => $tenantId, 'name' => 'Pondok Lubna', 'subdomain' => 'lubna']);

        // 2. Seed Parent Data: Academic Classes, Subjects, & Memberships
        DB::table('academic_classes')->insert([
            'id' => $classId,
            'tenant_id' => $tenantId,
            'name' => 'Kelas 1A',
            'code' => 'K1A',
            'tingkat' => '1',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('academic_subjects')->insert([
            'id' => $subjectId,
            'tenant_id' => $tenantId,
            'name' => 'Fiqih',
            'code' => 'FQH-01',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('memberships')->insert([
            ['id' => $membershipAId, 'user_id' => $userSantriAId, 'tenant_id' => $tenantId, 'role' => 'SANTRI', 'status' => 'ACTIVE', 'created_at' => now(), 'updated_at' => now()],
            ['id' => $membershipBId, 'user_id' => $userSantriBId, 'tenant_id' => $tenantId, 'role' => 'SANTRI', 'status' => 'ACTIVE', 'created_at' => now(), 'updated_at' => now()],
        ]);

        // 3. Seed Child Data: Santris (Sesuai skema fisik DB)
        DB::table('santris')->insert([
            [
                'id' => $santriAId,
                'tenant_id' => $tenantId,
                'class_id' => $classId,
                'nis' => '1001',
                'name' => 'Ali',
                'gender' => 'L',
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'id' => $santriBId,
                'tenant_id' => $tenantId,
                'class_id' => $classId,
                'nis' => '1002',
                'name' => 'Budi',
                'gender' => 'L',
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);

        DB::table('assessment_settings')->insert([
            'id' => $settingId,
            'tenant_id' => $tenantId,
            'academic_period_id' => $periodId,
            'academic_subject_id' => $subjectId,
            'component_name' => 'TUGAS',
            'weight' => 20.00,
        ]);

        $teacherModel = User::find($teacherId);
        session(['active_tenant_id' => $tenantId]);

        $payload = [
            'assessment_setting_id' => $settingId,
            'teacher_id' => $teacherId,
            'grades' => [
                ['santri_id' => $santriAId, 'score' => 85.50, 'notes' => 'Sangat Baik'],
                ['santri_id' => $santriBId, 'score' => 70.00, 'notes' => 'Cukup'],
            ],
        ];

        $response = $this->actingAs($teacherModel)->json('POST', '/api/v1/academic/grades/bulk', $payload);

        $response->assertStatus(200);
        $this->assertDatabaseHas('student_grades', ['santri_id' => $santriAId, 'score' => 85.50]);
    }

    /**
     * Memastikan sistem menolak input nilai jika komponen penilaian milik institusi/sekolah lain.
     */
    public function test_bulk_grading_is_forbidden_for_assessment_settings_of_another_tenant(): void
    {
        $tenantAId = (string) Str::uuid();
        $tenantBId = (string) Str::uuid();
        $teacherId = (string) Str::uuid();
        $classId = (string) Str::uuid();
        $subjectId = (string) Str::uuid();
        $userSantriId = (string) Str::uuid();
        $membershipId = (string) Str::uuid();
        $santriId = (string) Str::uuid();
        $settingSchoolBId = (string) Str::uuid();

        DB::table('users')->insert([
            ['id' => $teacherId, 'name' => 'Guru Sekolah A', 'email' => 'guru@sekolaha.test', 'password' => 'secret', 'is_superadmin' => false],
            ['id' => $userSantriId, 'name' => 'Santri User', 'email' => 'santri@sekolaha.test', 'password' => 'secret', 'is_superadmin' => false],
        ]);

        DB::table('tenants')->insert([
            ['id' => $tenantAId, 'name' => 'Sekolah A', 'subdomain' => 'sekolah-a'],
            ['id' => $tenantBId, 'name' => 'Sekolah B', 'subdomain' => 'sekolah-b'],
        ]);

        DB::table('academic_classes')->insert([
            'id' => $classId,
            'tenant_id' => $tenantAId,
            'name' => 'Kelas 1A',
            'code' => 'K1A',
            'tingkat' => '1',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('academic_subjects')->insert([
            'id' => $subjectId,
            'tenant_id' => $tenantAId,
            'name' => 'Hadits',
            'code' => 'HDT-01',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('memberships')->insert(['id' => $membershipId, 'user_id' => $userSantriId, 'tenant_id' => $tenantAId, 'role' => 'SANTRI', 'status' => 'ACTIVE', 'created_at' => now(), 'updated_at' => now()]);

        DB::table('santris')->insert(['id' => $santriId, 'tenant_id' => $tenantAId, 'class_id' => $classId, 'nis' => '2001', 'name' => 'Santri Isolation', 'gender' => 'L', 'created_at' => now(), 'updated_at' => now()]);

        DB::table('assessment_settings')->insert([
            'id' => $settingSchoolBId,
            'tenant_id' => $tenantBId,
            'academic_period_id' => (string) Str::uuid(),
            'academic_subject_id' => $subjectId,
            'component_name' => 'UTS',
            'weight' => 30.00,
        ]);

        $teacherModel = User::find($teacherId);
        session(['active_tenant_id' => $tenantAId]);

        $payload = [
            'assessment_setting_id' => $settingSchoolBId,
            'teacher_id' => $teacherId,
            'grades' => [['santri_id' => $santriId, 'score' => 90.00]],
        ];

        $response = $this->actingAs($teacherModel)->json('POST', '/api/v1/academic/grades/bulk', $payload);

        $response->assertStatus(403);
    }

    /**
     * Uji Akurasi Agregasi Kalkulasi Rapor.
     */
    public function test_academic_report_score_calculation_accuracy(): void
    {
        $tenantId = (string) Str::uuid();
        $periodId = (string) Str::uuid();
        $subjectId = (string) Str::uuid();
        $classId = (string) Str::uuid();
        $userSantriId = (string) Str::uuid();
        $membershipId = (string) Str::uuid();
        $santriId = (string) Str::uuid();

        $settingTugasId = (string) Str::uuid();
        $settingUtsId = (string) Str::uuid();
        $settingUasId = (string) Str::uuid();

        DB::table('users')->insert(['id' => $userSantriId, 'name' => 'Santri Target', 'email' => 'target@educore.test', 'password' => 'secret', 'is_superadmin' => false]);
        DB::table('tenants')->insert(['id' => $tenantId, 'name' => 'Pondok Lubna', 'subdomain' => 'lubna']);

        DB::table('academic_classes')->insert([
            'id' => $classId,
            'tenant_id' => $tenantId,
            'name' => 'Kelas 1A',
            'code' => 'K1A',
            'tingkat' => '1',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('academic_subjects')->insert([
            'id' => $subjectId,
            'tenant_id' => $tenantId,
            'name' => 'Tauhid',
            'code' => 'THD-01',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('memberships')->insert(['id' => $membershipId, 'user_id' => $userSantriId, 'tenant_id' => $tenantId, 'role' => 'SANTRI', 'status' => 'ACTIVE', 'created_at' => now(), 'updated_at' => now()]);

        DB::table('santris')->insert(['id' => $santriId, 'tenant_id' => $tenantId, 'class_id' => $classId, 'nis' => '3001', 'name' => 'Santri Target', 'gender' => 'L', 'created_at' => now(), 'updated_at' => now()]);

        DB::table('assessment_settings')->insert([
            ['id' => $settingTugasId, 'tenant_id' => $tenantId, 'academic_period_id' => $periodId, 'academic_subject_id' => $subjectId, 'component_name' => 'TUGAS', 'weight' => 20.00],
            ['id' => $settingUtsId, 'tenant_id' => $tenantId, 'academic_period_id' => $periodId, 'academic_subject_id' => $subjectId, 'component_name' => 'UTS', 'weight' => 30.00],
            ['id' => $settingUasId, 'tenant_id' => $tenantId, 'academic_period_id' => $periodId, 'academic_subject_id' => $subjectId, 'component_name' => 'UAS', 'weight' => 50.00],
        ]);

        DB::table('student_grades')->insert([
            ['id' => (string) Str::uuid(), 'tenant_id' => $tenantId, 'assessment_setting_id' => $settingTugasId, 'santri_id' => $santriId, 'teacher_id' => (string) Str::uuid(), 'score' => 80.00, 'created_at' => now(), 'updated_at' => now()],
            ['id' => (string) Str::uuid(), 'tenant_id' => $tenantId, 'assessment_setting_id' => $settingUtsId, 'santri_id' => $santriId, 'teacher_id' => (string) Str::uuid(), 'score' => 70.00, 'created_at' => now(), 'updated_at' => now()],
            ['id' => (string) Str::uuid(), 'tenant_id' => $tenantId, 'assessment_setting_id' => $settingUasId, 'santri_id' => $santriId, 'teacher_id' => (string) Str::uuid(), 'score' => 90.00, 'created_at' => now(), 'updated_at' => now()],
        ]);

        $reportResult = DB::table('student_grades')
            ->join('assessment_settings', 'student_grades.assessment_setting_id', '=', 'assessment_settings.id')
            ->where('student_grades.santri_id', $santriId)
            ->where('assessment_settings.academic_period_id', $periodId)
            ->select(DB::raw('SUM(student_grades.score * (assessment_settings.weight / 100)) as final_report_score'))
            ->first();

        $this->assertEquals(82.00, (float) $reportResult->final_report_score);
    }
}
