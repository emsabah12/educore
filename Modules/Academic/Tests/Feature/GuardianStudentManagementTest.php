<?php

declare(strict_types=1);

namespace Modules\Academic\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Modules\Core\Tests\TestCase;

final class GuardianStudentManagementTest extends TestCase
{
    use RefreshDatabase;

    public function test_can_bind_and_unbind_many_to_many_relationships_safely(): void
    {
        $tenantId = (string) Str::uuid();
        $classId = (string) Str::uuid();
        $studentId = (string) Str::uuid();

        $guardianUserId = (string) Str::uuid();
        $guardianMembershipId = (string) Str::uuid();
        $guardianId = (string) Str::uuid();

        /*
         * 1. Seed Tenant
         */
        DB::table('tenants')->insert([
            'id' => $tenantId,
            'name' => 'Pesantren Wali Test',
            'subdomain' => 'pesantren-wali-' . Str::random(5),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        /*
         * 2. Seed Academic Class
         */
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

        /*
         * 3. Seed Student
         */
        DB::table('students')->insert([
            'id' => $studentId,
            'tenant_id' => $tenantId,
            'class_id' => $classId,
            'nis' => '1111',
            'name' => 'Anak Student',
            'gender' => 'L',
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        /*
         * 4. Seed Guardian User
         *
         * Identitas guardian disimpan di tabel users.
         */
        DB::table('users')->insert([
            'id' => $guardianUserId,
            'name' => 'Orang Tua Student',
            'email' => 'orangtua.student@example.test',
            'password' => Hash::make('PasswordTest123!'),
            'status' => 'ACTIVE',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        /*
         * 5. Seed Guardian Membership
         *
         * Membership menghubungkan user dengan tenant.
         */
        DB::table('memberships')->insert([
            'id' => $guardianMembershipId,
            'user_id' => $guardianUserId,
            'tenant_id' => $tenantId,
            'role' => 'guardian',
            'status' => 'ACTIVE',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        /*
         * 6. Seed Guardian Profile
         *
         * Schema aktual guardians:
         * - id
         * - tenant_id
         * - membership_id
         * - no_hp
         * - alamat_domisili
         */
        DB::table('guardians')->insert([
            'id' => $guardianId,
            'tenant_id' => $tenantId,
            'membership_id' => $guardianMembershipId,
            'no_hp' => '08123456789',
            'alamat_domisili' => 'Alamat Test',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        /*
 * 7. Bind Guardian dengan Student
 */
        $guardianStudentId = (string) Str::uuid();

        DB::table('guardian_student')->insert([
            'id' => $guardianStudentId,
            'tenant_id' => $tenantId,
            'guardian_id' => $guardianId,
            'student_id' => $studentId,
            'relationship_type' => 'FATHER',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        /*
 * 8. Pastikan relationship berhasil dibuat
 */
        $this->assertDatabaseHas('guardian_student', [
            'id' => $guardianStudentId,
            'tenant_id' => $tenantId,
            'guardian_id' => $guardianId,
            'student_id' => $studentId,
            'relationship_type' => 'FATHER',
        ]);

        /*
 * 9. Unbind Guardian dengan Student
 */
        DB::table('guardian_student')
            ->where('tenant_id', $tenantId)
            ->where('guardian_id', $guardianId)
            ->where('student_id', $studentId)
            ->delete();

        /*
 * 10. Pastikan relationship berhasil dihapus
 */
        $this->assertDatabaseMissing('guardian_student', [
            'tenant_id' => $tenantId,
            'guardian_id' => $guardianId,
            'student_id' => $studentId,
        ]);
    }
}
