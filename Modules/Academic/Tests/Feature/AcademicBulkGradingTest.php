<?php

declare(strict_types=1);

namespace Modules\Academic\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Mockery\MockInterface;
use Modules\Academic\Database\Seeders\AcademicAuthorizationCatalogSeeder;
use Modules\Auth\Token\Contracts\TokenManagerInterface;
use Modules\Core\Authorization\Database\Seeders\AuthorizationCatalogSeeder;
use Modules\Core\Support\Uuid\UuidV7;
use Modules\HR\Contracts\EmployeeRepositoryInterface;
use RuntimeException;
use Tests\TestCase;

final class AcademicBulkGradingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(AuthorizationCatalogSeeder::class);
        $this->seed(AcademicAuthorizationCatalogSeeder::class);
    }

    public function test_teacher_permission_resolves_employee_actor_and_writes_uuid_v7_grade(): void
    {
        $tenantId = $this->createTenant('Teacher Permission Tenant');
        $actor = $this->createAuthenticatedActor(
            $tenantId,
            jabatan: 'STAFF',
            assignTeacherRole: true,
        );
        $student = $this->createStudent($tenantId, 'Canonical Grade Student');
        $assessmentSettingId = $this->createAssessmentSetting($tenantId, 'TUGAS', 20.00);

        $response = $this
            ->withToken($actor['token'])
            ->postJson(route('api.v1.academic.grades.bulk', [], false), [
                'assessment_setting_id' => $assessmentSettingId,
                'grades' => [[
                    'student_id' => $student['student_id'],
                    'score' => 85.50,
                    'notes' => 'Sangat Baik',
                ]],
            ]);

        $response
            ->assertOk()
            ->assertJsonPath('status', 'success')
            ->assertJsonPath('data.processed', 1);

        $grade = DB::table('student_grades')
            ->where('assessment_setting_id', $assessmentSettingId)
            ->where('student_id', $student['student_id'])
            ->first();

        $this->assertNotNull($grade);
        $this->assertTrue(UuidV7::validate((string) $grade->id));
        $this->assertSame($tenantId, (string) $grade->tenant_id);
        $this->assertSame($actor['employee_id'], (string) $grade->teacher_id);
        $this->assertNotSame($actor['user_id'], (string) $grade->teacher_id);
        $this->assertSame('STAFF', $actor['jabatan']);
    }

    public function test_guru_position_without_teacher_permission_is_forbidden(): void
    {
        $tenantId = $this->createTenant('No Permission Tenant');
        $actor = $this->createAuthenticatedActor(
            $tenantId,
            jabatan: 'GURU',
            assignTeacherRole: false,
        );
        $student = $this->createStudent($tenantId, 'No Permission Student');
        $assessmentSettingId = $this->createAssessmentSetting($tenantId, 'UTS', 30.00);

        $this
            ->withToken($actor['token'])
            ->postJson(route('api.v1.academic.grades.bulk', [], false), [
                'assessment_setting_id' => $assessmentSettingId,
                'grades' => [[
                    'student_id' => $student['student_id'],
                    'score' => 90,
                ]],
            ])
            ->assertForbidden();

        $this->assertDatabaseCount('student_grades', 0);
    }

    public function test_teacher_permission_without_employee_profile_is_forbidden(): void
    {
        $tenantId = $this->createTenant('Missing Employee Tenant');
        $actor = $this->createAuthenticatedActor(
            $tenantId,
            createEmployee: false,
            assignTeacherRole: true,
        );
        $student = $this->createStudent($tenantId, 'Missing Employee Student');
        $assessmentSettingId = $this->createAssessmentSetting($tenantId, 'UAS', 50.00);

        $this
            ->withToken($actor['token'])
            ->postJson(route('api.v1.academic.grades.bulk', [], false), [
                'assessment_setting_id' => $assessmentSettingId,
                'grades' => [[
                    'student_id' => $student['student_id'],
                    'score' => 88,
                ]],
            ])
            ->assertForbidden();

        $this->assertDatabaseCount('student_grades', 0);
    }

    public function test_soft_deleted_or_corrupted_employee_actor_is_forbidden(): void
    {
        $tenantId = $this->createTenant('Employee Actor Isolation Tenant');
        $otherTenantId = $this->createTenant('Employee Actor Other Tenant');
        $student = $this->createStudent($tenantId, 'Actor Isolation Student');
        $assessmentSettingId = $this->createAssessmentSetting($tenantId, 'PRAKTIK', 40.00);

        $softDeletedActor = $this->createAuthenticatedActor(
            $tenantId,
            assignTeacherRole: true,
            softDeleteEmployee: true,
        );

        $this
            ->withToken($softDeletedActor['token'])
            ->postJson(route('api.v1.academic.grades.bulk', [], false), [
                'assessment_setting_id' => $assessmentSettingId,
                'grades' => [[
                    'student_id' => $student['student_id'],
                    'score' => 81,
                ]],
            ])
            ->assertForbidden();

        $corruptedActor = $this->createAuthenticatedActor(
            $tenantId,
            assignTeacherRole: true,
            employeeTenantId: $otherTenantId,
        );

        $this
            ->withToken($corruptedActor['token'])
            ->postJson(route('api.v1.academic.grades.bulk', [], false), [
                'assessment_setting_id' => $assessmentSettingId,
                'grades' => [[
                    'student_id' => $student['student_id'],
                    'score' => 82,
                ]],
            ])
            ->assertForbidden();

        $this->assertDatabaseCount('student_grades', 0);
    }

    public function test_request_rejects_teacher_spoof_duplicate_students_and_non_v7_ids(): void
    {
        $tenantId = $this->createTenant('Validation Tenant');
        $actor = $this->createAuthenticatedActor(
            $tenantId,
            assignTeacherRole: true,
        );
        $student = $this->createStudent($tenantId, 'Validation Student');
        $assessmentSettingId = $this->createAssessmentSetting($tenantId, 'VALIDATION', 10.00);
        $route = route('api.v1.academic.grades.bulk', [], false);

        $this
            ->withToken($actor['token'])
            ->postJson($route, [
                'assessment_setting_id' => $assessmentSettingId,
                'teacher_id' => UuidV7::generate(),
                'grades' => [[
                    'student_id' => $student['student_id'],
                    'score' => 80,
                ]],
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['teacher_id']);

        $this
            ->withToken($actor['token'])
            ->postJson($route, [
                'assessment_setting_id' => $assessmentSettingId,
                'grades' => [
                    [
                        'student_id' => $student['student_id'],
                        'score' => 80,
                    ],
                    [
                        'student_id' => $student['student_id'],
                        'score' => 90,
                    ],
                ],
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['grades.0.student_id']);

        $this
            ->withToken($actor['token'])
            ->postJson($route, [
                'assessment_setting_id' => (string) Str::uuid(),
                'grades' => [[
                    'student_id' => $student['student_id'],
                    'score' => 80,
                ]],
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['assessment_setting_id']);

        $this->assertDatabaseCount('student_grades', 0);
    }

    public function test_assessment_and_student_targets_are_tenant_scoped_and_soft_delete_safe(): void
    {
        $tenantId = $this->createTenant('Target Tenant A');
        $otherTenantId = $this->createTenant('Target Tenant B');
        $actor = $this->createAuthenticatedActor(
            $tenantId,
            assignTeacherRole: true,
        );
        $validStudent = $this->createStudent($tenantId, 'Valid Target Student');
        $crossTenantStudent = $this->createStudent($otherTenantId, 'Cross Tenant Student');
        $softDeletedStudent = $this->createStudent(
            $tenantId,
            'Soft Deleted Student',
            softDeleted: true,
        );
        $corruptStudent = $this->createStudent(
            $tenantId,
            'Corrupt Membership Student',
            membershipTenantId: $otherTenantId,
        );
        $validSettingId = $this->createAssessmentSetting($tenantId, 'LOCAL', 25.00);
        $crossTenantSettingId = $this->createAssessmentSetting($otherTenantId, 'REMOTE', 25.00);
        $route = route('api.v1.academic.grades.bulk', [], false);

        $this
            ->withToken($actor['token'])
            ->postJson($route, [
                'assessment_setting_id' => $crossTenantSettingId,
                'grades' => [[
                    'student_id' => $validStudent['student_id'],
                    'score' => 70,
                ]],
            ])
            ->assertNotFound();

        foreach ([
            $crossTenantStudent['student_id'],
            $softDeletedStudent['student_id'],
            $corruptStudent['student_id'],
        ] as $studentId) {
            $this
                ->withToken($actor['token'])
                ->postJson($route, [
                    'assessment_setting_id' => $validSettingId,
                    'grades' => [[
                        'student_id' => $studentId,
                        'score' => 70,
                    ]],
                ])
                ->assertNotFound();
        }

        $this->assertDatabaseCount('student_grades', 0);
    }

    public function test_resubmission_updates_grade_and_teacher_to_current_employee_actor(): void
    {
        $tenantId = $this->createTenant('Resubmission Tenant');
        $firstActor = $this->createAuthenticatedActor(
            $tenantId,
            assignTeacherRole: true,
        );
        $secondActor = $this->createAuthenticatedActor(
            $tenantId,
            assignTeacherRole: true,
        );
        $student = $this->createStudent($tenantId, 'Resubmission Student');
        $assessmentSettingId = $this->createAssessmentSetting($tenantId, 'RESUBMIT', 100.00);
        $route = route('api.v1.academic.grades.bulk', [], false);

        $this
            ->withToken($firstActor['token'])
            ->postJson($route, [
                'assessment_setting_id' => $assessmentSettingId,
                'grades' => [[
                    'student_id' => $student['student_id'],
                    'score' => 75,
                ]],
            ])
            ->assertOk();

        $firstGradeId = (string) DB::table('student_grades')
            ->where('assessment_setting_id', $assessmentSettingId)
            ->where('student_id', $student['student_id'])
            ->value('id');

        $this
            ->withToken($secondActor['token'])
            ->postJson($route, [
                'assessment_setting_id' => $assessmentSettingId,
                'grades' => [[
                    'student_id' => $student['student_id'],
                    'score' => 92,
                    'notes' => 'Updated',
                ]],
            ])
            ->assertOk();

        $grade = DB::table('student_grades')
            ->where('assessment_setting_id', $assessmentSettingId)
            ->where('student_id', $student['student_id'])
            ->first();

        $this->assertNotNull($grade);
        $this->assertSame($firstGradeId, (string) $grade->id);
        $this->assertSame($secondActor['employee_id'], (string) $grade->teacher_id);
        $this->assertEquals(92.00, (float) $grade->score);
        $this->assertSame('Updated', (string) $grade->notes);
        $this->assertSame(
            1,
            DB::table('student_grades')
                ->where('assessment_setting_id', $assessmentSettingId)
                ->where('student_id', $student['student_id'])
                ->count(),
        );
    }

    public function test_unexpected_employee_resolution_failure_returns_generic_error(): void
    {
        $tenantId = $this->createTenant('Failure Tenant');
        $actor = $this->createAuthenticatedActor(
            $tenantId,
            assignTeacherRole: true,
        );
        $student = $this->createStudent($tenantId, 'Failure Student');
        $assessmentSettingId = $this->createAssessmentSetting($tenantId, 'FAILURE', 20.00);

        $this->mock(
            EmployeeRepositoryInterface::class,
            static function (MockInterface $mock): void {
                $mock->shouldReceive('findByMembershipForTenant')
                    ->once()
                    ->andThrow(new RuntimeException('secret employee resolution failure'));
            },
        );

        $response = $this
            ->withToken($actor['token'])
            ->postJson(route('api.v1.academic.grades.bulk', [], false), [
                'assessment_setting_id' => $assessmentSettingId,
                'grades' => [[
                    'student_id' => $student['student_id'],
                    'score' => 77,
                ]],
            ]);

        $response
            ->assertInternalServerError()
            ->assertJsonPath(
                'message',
                'Terjadi kesalahan saat menyimpan nilai student.',
            );

        $this->assertStringNotContainsString(
            'secret employee resolution failure',
            $response->getContent(),
        );
        $this->assertDatabaseCount('student_grades', 0);
    }

    public function test_academic_authorization_catalog_is_idempotent_and_does_not_grant_admin_implicitly(): void
    {
        $this->seed(AcademicAuthorizationCatalogSeeder::class);

        $teacherRoleId = (string) DB::table('roles')
            ->where('name', AcademicAuthorizationCatalogSeeder::TEACHER_ROLE)
            ->value('id');
        $permissionId = (string) DB::table('permissions')
            ->where('name', AcademicAuthorizationCatalogSeeder::GRADES_WRITE_PERMISSION)
            ->value('id');
        $adminRoleId = (string) DB::table('roles')
            ->where('name', 'admin')
            ->value('id');

        $this->assertTrue(UuidV7::validate($teacherRoleId));
        $this->assertTrue(UuidV7::validate($permissionId));
        $this->assertSame(
            'Academic',
            DB::table('permissions')
                ->where('id', $permissionId)
                ->value('module'),
        );
        $this->assertSame(
            1,
            DB::table('role_permissions')
                ->where('role_id', $teacherRoleId)
                ->where('permission_id', $permissionId)
                ->count(),
        );
        $this->assertSame(
            0,
            DB::table('role_permissions')
                ->where('role_id', $adminRoleId)
                ->where('permission_id', $permissionId)
                ->count(),
        );
    }

    public function test_academic_report_score_calculation_accuracy_with_canonical_profiles(): void
    {
        $tenantId = $this->createTenant('Report Calculation Tenant');
        $actor = $this->createAuthenticatedActor(
            $tenantId,
            assignTeacherRole: false,
        );
        $student = $this->createStudent($tenantId, 'Report Student');
        $periodId = UuidV7::generate();

        $settingTugasId = $this->createAssessmentSetting(
            $tenantId,
            'TUGAS',
            20.00,
            $periodId,
        );
        $settingUtsId = $this->createAssessmentSetting(
            $tenantId,
            'UTS',
            30.00,
            $periodId,
        );
        $settingUasId = $this->createAssessmentSetting(
            $tenantId,
            'UAS',
            50.00,
            $periodId,
        );

        foreach ([
            [$settingTugasId, 80.00],
            [$settingUtsId, 70.00],
            [$settingUasId, 90.00],
        ] as [$settingId, $score]) {
            DB::table('student_grades')->insert([
                'id' => UuidV7::generate(),
                'tenant_id' => $tenantId,
                'assessment_setting_id' => $settingId,
                'student_id' => $student['student_id'],
                'teacher_id' => $actor['employee_id'],
                'score' => $score,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        $reportResult = DB::table('student_grades')
            ->join(
                'assessment_settings',
                'student_grades.assessment_setting_id',
                '=',
                'assessment_settings.id',
            )
            ->where('student_grades.student_id', $student['student_id'])
            ->where('assessment_settings.academic_period_id', $periodId)
            ->select(DB::raw(
                'SUM(student_grades.score * (assessment_settings.weight / 100)) as final_report_score'
            ))
            ->first();

        $this->assertNotNull($reportResult);
        $this->assertEquals(82.00, (float) $reportResult->final_report_score);
    }

    /**
     * @return array{
     *     person_id:string,
     *     user_id:string,
     *     membership_id:string,
     *     employee_id:string,
     *     jabatan:string,
     *     token:string
     * }
     */
    private function createAuthenticatedActor(
        string $tenantId,
        string $jabatan = 'STAFF',
        bool $createEmployee = true,
        bool $assignTeacherRole = false,
        bool $softDeleteEmployee = false,
        ?string $employeeTenantId = null,
    ): array {
        $personId = UuidV7::generate();
        $userId = UuidV7::generate();
        $membershipId = UuidV7::generate();
        $employeeId = $createEmployee
            ? UuidV7::generate()
            : '';

        DB::table('persons')->insert([
            'id' => $personId,
            'name' => 'Authenticated Grading Actor',
            'status' => 'ACTIVE',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('users')->insert([
            'id' => $userId,
            'person_id' => $personId,
            'email' => sprintf(
                'grading-%s@educore.test',
                Str::lower(Str::random(16)),
            ),
            'password' => 'not-used-by-token-test',
            'status' => 'ACTIVE',
            'is_superadmin' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('memberships')->insert([
            'id' => $membershipId,
            'person_id' => $personId,
            'tenant_id' => $tenantId,
            'status' => 'ACTIVE',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        if ($createEmployee) {
            DB::table('employees')->insert([
                'id' => $employeeId,
                'tenant_id' => $employeeTenantId ?? $tenantId,
                'membership_id' => $membershipId,
                'nip' => sprintf(
                    'GR-%s',
                    Str::upper(Str::random(10)),
                ),
                'jabatan' => $jabatan,
                'created_at' => now(),
                'updated_at' => now(),
                'deleted_at' => $softDeleteEmployee ? now() : null,
            ]);
        }

        if ($assignTeacherRole) {
            $this->assignTeacherRole($membershipId);
        }

        return [
            'person_id' => $personId,
            'user_id' => $userId,
            'membership_id' => $membershipId,
            'employee_id' => $employeeId,
            'jabatan' => $jabatan,
            'token' => $this->issueToken(
                $userId,
                $tenantId,
                $membershipId,
            ),
        ];
    }

    /**
     * @return array{person_id:string,membership_id:string,student_id:string}
     */
    private function createStudent(
        string $studentTenantId,
        string $name,
        ?string $membershipTenantId = null,
        bool $softDeleted = false,
    ): array {
        $personId = UuidV7::generate();
        $membershipId = UuidV7::generate();
        $studentId = UuidV7::generate();

        DB::table('persons')->insert([
            'id' => $personId,
            'name' => $name,
            'status' => 'ACTIVE',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('memberships')->insert([
            'id' => $membershipId,
            'person_id' => $personId,
            'tenant_id' => $membershipTenantId ?? $studentTenantId,
            'status' => 'ACTIVE',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('students')->insert([
            'id' => $studentId,
            'tenant_id' => $studentTenantId,
            'membership_id' => $membershipId,
            'class_id' => null,
            'nis' => sprintf(
                'ST-%s',
                Str::upper(Str::random(10)),
            ),
            'nisn' => null,
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
            'deleted_at' => $softDeleted ? now() : null,
        ]);

        return [
            'person_id' => $personId,
            'membership_id' => $membershipId,
            'student_id' => $studentId,
        ];
    }

    private function createAssessmentSetting(
        string $tenantId,
        string $componentName,
        float $weight,
        ?string $periodId = null,
    ): string {
        $settingId = UuidV7::generate();

        DB::table('assessment_settings')->insert([
            'id' => $settingId,
            'tenant_id' => $tenantId,
            'academic_period_id' => $periodId ?? UuidV7::generate(),
            'academic_subject_id' => UuidV7::generate(),
            'component_name' => $componentName,
            'weight' => $weight,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $settingId;
    }

    private function createTenant(string $name): string
    {
        $tenantId = UuidV7::generate();

        DB::table('tenants')->insert([
            'id' => $tenantId,
            'name' => $name,
            'subdomain' => sprintf(
                'grading-%s',
                Str::lower(Str::random(12)),
            ),
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $tenantId;
    }

    private function assignTeacherRole(string $membershipId): void
    {
        $teacherRoleId = DB::table('roles')
            ->where('name', AcademicAuthorizationCatalogSeeder::TEACHER_ROLE)
            ->value('id');

        $this->assertIsString($teacherRoleId);

        DB::table('membership_roles')->insertOrIgnore([
            'membership_id' => $membershipId,
            'role_id' => $teacherRoleId,
        ]);
    }

    private function issueToken(
        string $userId,
        string $tenantId,
        string $membershipId,
    ): string {
        return app(TokenManagerInterface::class)
            ->issueToken(
                $userId,
                $tenantId,
                ['membership_id' => $membershipId],
            );
    }
}
