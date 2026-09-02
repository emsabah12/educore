<?php

declare(strict_types=1);

namespace Modules\Academic\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Mockery\MockInterface;
use Modules\Academic\Contracts\GuardianStudentRepositoryInterface;
use Modules\Academic\Database\Seeders\AcademicAuthorizationCatalogSeeder;
use Modules\Auth\Token\Contracts\TokenManagerInterface;
use Modules\Core\Support\Uuid\UuidV7;
use RuntimeException;
use Tests\Support\GrantsAuthorizationRole;
use Tests\TestCase;

final class GuardianStudentManagementTest extends TestCase
{
    use RefreshDatabase;
    use GrantsAuthorizationRole;

    private string $tenantId;
    private string $operatorPersonId;
    private string $operatorUserId;
    private string $operatorMembershipId;
    private string $classId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(AcademicAuthorizationCatalogSeeder::class);

        $this->tenantId = UuidV7::generate();
        $this->operatorPersonId = UuidV7::generate();
        $this->operatorUserId = UuidV7::generate();
        $this->operatorMembershipId = UuidV7::generate();
        $this->classId = UuidV7::generate();

        $this->createAuthenticatedTenantFixture();
    }

    public function test_attach_is_canonical_uuid_v7_normalized_and_idempotent(): void
    {
        $guardian = $this->createGuardianProfile(
            $this->tenantId,
            'Canonical Guardian',
        );
        $student = $this->createStudentProfile(
            $this->tenantId,
            'Canonical Student',
            $this->classId,
        );

        $storeRoute = route(
            'api.v1.academic.guardians.associations.store',
            [],
            false,
        );

        $this
            ->withToken($this->issueToken())
            ->postJson($storeRoute, [
                'guardian_id' => $guardian['guardian_id'],
                'student_id' => $student['student_id'],
                'relationship_type' => '  father  ',
            ])
            ->assertOk()
            ->assertJsonPath('status', 'success');

        $association = DB::table('guardian_student')
            ->where('tenant_id', $this->tenantId)
            ->where('guardian_id', $guardian['guardian_id'])
            ->where('student_id', $student['student_id'])
            ->first();

        $this->assertNotNull($association);
        $this->assertTrue(UuidV7::validate((string) $association->id));
        $this->assertSame('FATHER', $association->relationship_type);

        $this
            ->withToken($this->issueToken())
            ->postJson($storeRoute, [
                'guardian_id' => $guardian['guardian_id'],
                'student_id' => $student['student_id'],
                'relationship_type' => 'MOTHER',
            ])
            ->assertOk();

        $this->assertSame(
            1,
            DB::table('guardian_student')
                ->where('tenant_id', $this->tenantId)
                ->where('guardian_id', $guardian['guardian_id'])
                ->where('student_id', $student['student_id'])
                ->count(),
        );
        $this->assertDatabaseHas('guardian_student', [
            'id' => (string) $association->id,
            'relationship_type' => 'FATHER',
        ]);
        $this->assertSame(
            1,
            DB::table('audit_logs')
                ->where('event_type', 'guardian_student.attached')
                ->count(),
            'Idempotent re-attach must not emit a false second attach audit event.',
        );

        $audit = DB::table('audit_logs')
            ->where('event_type', 'guardian_student.attached')
            ->orderByDesc('created_at')
            ->first();

        $this->assertNotNull($audit);

        /** @var array<string, mixed> $metadata */
        $metadata = json_decode(
            (string) $audit->metadata,
            true,
            flags: JSON_THROW_ON_ERROR,
        );

        $this->assertSame(
            $guardian['guardian_id'],
            $metadata['guardian_id'] ?? null,
        );
        $this->assertSame(
            $student['student_id'],
            $metadata['student_id'] ?? null,
        );
        $this->assertArrayNotHasKey('relationship_type', $metadata);
    }

    public function test_attach_fails_closed_for_cross_tenant_or_soft_deleted_profiles(): void
    {
        $guardian = $this->createGuardianProfile(
            $this->tenantId,
            'Current Tenant Guardian',
        );
        $student = $this->createStudentProfile(
            $this->tenantId,
            'Current Tenant Student',
            $this->classId,
        );

        $otherTenantId = UuidV7::generate();
        $this->createTenant(
            $otherTenantId,
            'Other Guardian Student Tenant',
        );
        $otherGuardian = $this->createGuardianProfile(
            $otherTenantId,
            'Other Tenant Guardian',
        );
        $otherStudent = $this->createStudentProfile(
            $otherTenantId,
            'Other Tenant Student',
            null,
        );

        $storeRoute = route(
            'api.v1.academic.guardians.associations.store',
            [],
            false,
        );

        $this
            ->withToken($this->issueToken())
            ->postJson($storeRoute, [
                'guardian_id' => $guardian['guardian_id'],
                'student_id' => $otherStudent['student_id'],
                'relationship_type' => 'FATHER',
            ])
            ->assertNotFound();

        $this
            ->withToken($this->issueToken())
            ->postJson($storeRoute, [
                'guardian_id' => $otherGuardian['guardian_id'],
                'student_id' => $student['student_id'],
                'relationship_type' => 'MOTHER',
            ])
            ->assertNotFound();

        $this
            ->withToken($this->issueToken())
            ->postJson($storeRoute, [
                'guardian_id' => UuidV7::generate(),
                'student_id' => $student['student_id'],
                'relationship_type' => 'GUARDIAN',
            ])
            ->assertNotFound();

        $this
            ->withToken($this->issueToken())
            ->postJson($storeRoute, [
                'guardian_id' => $guardian['guardian_id'],
                'student_id' => UuidV7::generate(),
                'relationship_type' => 'GUARDIAN',
            ])
            ->assertNotFound();

        DB::table('guardians')
            ->where('id', $guardian['guardian_id'])
            ->update([
                'deleted_at' => now(),
                'updated_at' => now(),
            ]);

        $this
            ->withToken($this->issueToken())
            ->postJson($storeRoute, [
                'guardian_id' => $guardian['guardian_id'],
                'student_id' => $student['student_id'],
                'relationship_type' => 'GUARDIAN',
            ])
            ->assertNotFound();

        DB::table('guardians')
            ->where('id', $guardian['guardian_id'])
            ->update([
                'deleted_at' => null,
                'updated_at' => now(),
            ]);
        DB::table('students')
            ->where('id', $student['student_id'])
            ->update([
                'deleted_at' => now(),
                'updated_at' => now(),
            ]);

        $this
            ->withToken($this->issueToken())
            ->postJson($storeRoute, [
                'guardian_id' => $guardian['guardian_id'],
                'student_id' => $student['student_id'],
                'relationship_type' => 'GUARDIAN',
            ])
            ->assertNotFound();

        $this->assertSame(
            0,
            DB::table('guardian_student')->count(),
        );
    }

    public function test_attach_rejects_corrupted_profile_membership_tenant_projection(): void
    {
        $otherTenantId = UuidV7::generate();
        $this->createTenant(
            $otherTenantId,
            'Corrupted Projection Tenant',
        );

        $guardianPersonId = UuidV7::generate();
        $guardianMembershipId = UuidV7::generate();
        $guardianId = UuidV7::generate();

        $this->createPerson(
            $guardianPersonId,
            'Corrupted Guardian Person',
        );
        $this->createMembership(
            $guardianMembershipId,
            $guardianPersonId,
            $otherTenantId,
        );
        DB::table('guardians')->insert([
            'id' => $guardianId,
            'tenant_id' => $this->tenantId,
            'membership_id' => $guardianMembershipId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $student = $this->createStudentProfile(
            $this->tenantId,
            'Valid Student',
            $this->classId,
        );

        $this
            ->withToken($this->issueToken())
            ->postJson(
                route(
                    'api.v1.academic.guardians.associations.store',
                    [],
                    false,
                ),
                [
                    'guardian_id' => $guardianId,
                    'student_id' => $student['student_id'],
                    'relationship_type' => 'GUARDIAN',
                ],
            )
            ->assertNotFound();

        $this->assertDatabaseMissing('guardian_student', [
            'guardian_id' => $guardianId,
            'student_id' => $student['student_id'],
        ]);
    }

    public function test_index_reads_canonical_student_identity_and_keeps_unassigned_student_visible(): void
    {
        $guardian = $this->createGuardianProfile(
            $this->tenantId,
            'Canonical Read Guardian',
        );
        $student = $this->createStudentProfile(
            $this->tenantId,
            'Original Student Name',
            null,
            membershipStatus: 'INACTIVE',
        );

        $this
            ->withToken($this->issueToken())
            ->postJson(
                route(
                    'api.v1.academic.guardians.associations.store',
                    [],
                    false,
                ),
                [
                    'guardian_id' => $guardian['guardian_id'],
                    'student_id' => $student['student_id'],
                    'relationship_type' => 'guardian',
                ],
            )
            ->assertOk();

        DB::table('persons')
            ->where('id', $student['person_id'])
            ->update([
                'name' => 'Updated Canonical Student Name',
                'updated_at' => now(),
            ]);

        $response = $this
            ->withToken($this->issueToken())
            ->getJson(
                route(
                    'api.v1.academic.guardians.students.index',
                    ['guardianId' => $guardian['guardian_id']],
                    false,
                ),
            );

        $response
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.student_id', $student['student_id'])
            ->assertJsonPath('data.0.membership_id', $student['membership_id'])
            ->assertJsonPath('data.0.person_id', $student['person_id'])
            ->assertJsonPath('data.0.class_id', null)
            ->assertJsonPath('data.0.nama', 'Updated Canonical Student Name')
            ->assertJsonPath('data.0.nama_kelas', null)
            ->assertJsonPath('data.0.tingkat', null)
            ->assertJsonPath('data.0.student_status', 'active')
            ->assertJsonPath('data.0.membership_status', 'INACTIVE')
            ->assertJsonPath('data.0.relationship_type', 'GUARDIAN');

        $this->assertNotNull(
            $response->json('data.0.relationship_created_at'),
        );

        /** @var array<string, mixed> $projection */
        $projection = $response->json('data.0');

        $this->assertArrayNotHasKey('user_id', $projection);
        $this->assertArrayNotHasKey('email', $projection);
        $this->assertArrayNotHasKey('student_name', $projection);
        $this->assertArrayNotHasKey('class_name', $projection);
        $this->assertArrayNotHasKey('created_at', $projection);
    }

    public function test_index_distinguishes_valid_empty_guardian_from_missing_or_invalid_guardian(): void
    {
        $guardian = $this->createGuardianProfile(
            $this->tenantId,
            'Empty Guardian',
        );

        $this
            ->withToken($this->issueToken())
            ->getJson(
                route(
                    'api.v1.academic.guardians.students.index',
                    ['guardianId' => $guardian['guardian_id']],
                    false,
                ),
            )
            ->assertOk()
            ->assertExactJson([
                'status' => 'success',
                'data' => [],
            ]);

        $this
            ->withToken($this->issueToken())
            ->getJson(
                route(
                    'api.v1.academic.guardians.students.index',
                    ['guardianId' => UuidV7::generate()],
                    false,
                ),
            )
            ->assertNotFound();

        $this
            ->withToken($this->issueToken())
            ->getJson(
                route(
                    'api.v1.academic.guardians.students.index',
                    ['guardianId' => (string) Str::uuid()],
                    false,
                ),
            )
            ->assertNotFound();

        $otherTenantId = UuidV7::generate();
        $this->createTenant(
            $otherTenantId,
            'Wrong Tenant Guardian Read',
        );
        $otherGuardian = $this->createGuardianProfile(
            $otherTenantId,
            'Wrong Tenant Guardian',
        );

        $this
            ->withToken($this->issueToken())
            ->getJson(
                route(
                    'api.v1.academic.guardians.students.index',
                    ['guardianId' => $otherGuardian['guardian_id']],
                    false,
                ),
            )
            ->assertNotFound();

        DB::table('guardians')
            ->where('id', $guardian['guardian_id'])
            ->update([
                'deleted_at' => now(),
                'updated_at' => now(),
            ]);

        $this
            ->withToken($this->issueToken())
            ->getJson(
                route(
                    'api.v1.academic.guardians.students.index',
                    ['guardianId' => $guardian['guardian_id']],
                    false,
                ),
            )
            ->assertNotFound();
    }

    public function test_detach_is_tenant_scoped_and_second_detach_returns_not_found(): void
    {
        $guardian = $this->createGuardianProfile(
            $this->tenantId,
            'Detach Guardian',
        );
        $student = $this->createStudentProfile(
            $this->tenantId,
            'Detach Student',
            $this->classId,
        );

        $this
            ->withToken($this->issueToken())
            ->postJson(
                route(
                    'api.v1.academic.guardians.associations.store',
                    [],
                    false,
                ),
                [
                    'guardian_id' => $guardian['guardian_id'],
                    'student_id' => $student['student_id'],
                    'relationship_type' => 'FATHER',
                ],
            )
            ->assertOk();

        $deleteRoute = route(
            'api.v1.academic.guardians.associations.destroy',
            [],
            false,
        );
        $payload = [
            'guardian_id' => $guardian['guardian_id'],
            'student_id' => $student['student_id'],
        ];

        $this
            ->withToken($this->issueToken())
            ->deleteJson($deleteRoute, $payload)
            ->assertOk()
            ->assertJsonPath('status', 'success');

        $this->assertDatabaseMissing('guardian_student', [
            'tenant_id' => $this->tenantId,
            'guardian_id' => $guardian['guardian_id'],
            'student_id' => $student['student_id'],
        ]);
        $this->assertDatabaseHas('audit_logs', [
            'tenant_id' => $this->tenantId,
            'event_type' => 'guardian_student.detached',
        ]);

        $this
            ->withToken($this->issueToken())
            ->deleteJson($deleteRoute, $payload)
            ->assertNotFound();
    }

    public function test_requests_reject_legacy_contracts_non_v7_ids_and_missing_relationship_type(): void
    {
        $guardian = $this->createGuardianProfile(
            $this->tenantId,
            'Validation Guardian',
        );
        $student = $this->createStudentProfile(
            $this->tenantId,
            'Validation Student',
            $this->classId,
        );

        $storeRoute = route(
            'api.v1.academic.guardians.associations.store',
            [],
            false,
        );

        $this
            ->withToken($this->issueToken())
            ->postJson($storeRoute, [
                'guardian_id' => $guardian['guardian_id'],
                'student_id' => $student['student_id'],
                'relationship_type' => 'FATHER',
                'hubungan' => 'AYAH',
                'relation' => 'FATHER',
                'walisantri_id' => $guardian['guardian_id'],
                'santri_id' => $student['student_id'],
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors([
                'hubungan',
                'relation',
                'walisantri_id',
                'santri_id',
            ]);

        $this
            ->withToken($this->issueToken())
            ->postJson($storeRoute, [
                'guardian_id' => (string) Str::uuid(),
                'student_id' => $student['student_id'],
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors([
                'guardian_id',
                'relationship_type',
            ]);

        $this
            ->withToken($this->issueToken())
            ->deleteJson(
                route(
                    'api.v1.academic.guardians.associations.destroy',
                    [],
                    false,
                ),
                [
                    'guardian_id' => $guardian['guardian_id'],
                    'student_id' => $student['student_id'],
                    'walisantri_id' => $guardian['guardian_id'],
                    'santri_id' => $student['student_id'],
                ],
            )
            ->assertUnprocessable()
            ->assertJsonValidationErrors([
                'walisantri_id',
                'santri_id',
            ]);
    }

    public function test_unexpected_repository_failure_returns_generic_error_without_internal_details(): void
    {
        $guardianId = UuidV7::generate();
        $studentId = UuidV7::generate();

        $this->mock(
            GuardianStudentRepositoryInterface::class,
            function (MockInterface $mock): void {
                $mock->shouldReceive('attachStudentToGuardian')
                    ->once()
                    ->andThrow(
                        new RuntimeException(
                            'secret SQL guardian_student failure',
                        ),
                    );
            },
        );

        $response = $this
            ->withToken($this->issueToken())
            ->postJson(
                route(
                    'api.v1.academic.guardians.associations.store',
                    [],
                    false,
                ),
                [
                    'guardian_id' => $guardianId,
                    'student_id' => $studentId,
                    'relationship_type' => 'GUARDIAN',
                ],
            );

        $response
            ->assertInternalServerError()
            ->assertJsonPath(
                'message',
                'Failed to process guardian-student association.',
            );

        $this->assertStringNotContainsString(
            'secret SQL',
            (string) $response->getContent(),
        );
    }

    public function test_associations_store_is_forbidden_when_registrar_role_is_revoked(): void
    {
        DB::table('membership_roles')
            ->where('membership_id', $this->operatorMembershipId)
            ->delete();

        $this
            ->withToken($this->issueToken())
            ->postJson(
                route(
                    'api.v1.academic.guardians.associations.store',
                    [],
                    false,
                ),
                [
                    'guardian_id' => UuidV7::generate(),
                    'student_id' => UuidV7::generate(),
                    'relationship_type' => 'GUARDIAN',
                ],
            )
            ->assertForbidden();
    }

    public function test_guardians_students_index_is_forbidden_when_registrar_role_is_revoked(): void
    {
        DB::table('membership_roles')
            ->where('membership_id', $this->operatorMembershipId)
            ->delete();

        $this
            ->withToken($this->issueToken())
            ->getJson(
                route(
                    'api.v1.academic.guardians.students.index',
                    ['guardianId' => UuidV7::generate()],
                    false,
                ),
            )
            ->assertForbidden();
    }

    private function createAuthenticatedTenantFixture(): void
    {
        $this->createTenant(
            $this->tenantId,
            'Canonical Guardian Student Tenant',
        );
        $this->createPerson(
            $this->operatorPersonId,
            'Guardian Student Operator',
        );

        DB::table('users')->insert([
            'id' => $this->operatorUserId,
            'person_id' => $this->operatorPersonId,
            'email' => sprintf(
                'guardian-student-operator-%s@educore.test',
                Str::lower(Str::random(10)),
            ),
            'password' => 'not-used-by-token-test',
            'status' => 'ACTIVE',
            'is_superadmin' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->createMembership(
            $this->operatorMembershipId,
            $this->operatorPersonId,
            $this->tenantId,
        );
        $this->createClass(
            $this->classId,
            $this->tenantId,
            'Guardian Student Canonical Class',
        );

        $this->grantRole(
            $this->operatorMembershipId,
            AcademicAuthorizationCatalogSeeder::REGISTRAR_ROLE,
        );
    }

    /**
     * @return array{guardian_id:string,membership_id:string,person_id:string}
     */
    private function createGuardianProfile(
        string $tenantId,
        string $name,
        string $membershipStatus = 'ACTIVE',
    ): array {
        $personId = UuidV7::generate();
        $membershipId = UuidV7::generate();
        $guardianId = UuidV7::generate();

        $this->createPerson($personId, $name);
        $this->createMembership(
            $membershipId,
            $personId,
            $tenantId,
            $membershipStatus,
        );

        DB::table('guardians')->insert([
            'id' => $guardianId,
            'tenant_id' => $tenantId,
            'membership_id' => $membershipId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return [
            'guardian_id' => $guardianId,
            'membership_id' => $membershipId,
            'person_id' => $personId,
        ];
    }

    /**
     * @return array{student_id:string,membership_id:string,person_id:string}
     */
    private function createStudentProfile(
        string $tenantId,
        string $name,
        ?string $classId,
        string $membershipStatus = 'ACTIVE',
    ): array {
        $personId = UuidV7::generate();
        $membershipId = UuidV7::generate();
        $studentId = UuidV7::generate();

        $this->createPerson($personId, $name);
        $this->createMembership(
            $membershipId,
            $personId,
            $tenantId,
            $membershipStatus,
        );

        DB::table('students')->insert([
            'id' => $studentId,
            'tenant_id' => $tenantId,
            'membership_id' => $membershipId,
            'class_id' => $classId,
            'nis' => sprintf('GS-%s', Str::upper(Str::random(10))),
            'nisn' => null,
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return [
            'student_id' => $studentId,
            'membership_id' => $membershipId,
            'person_id' => $personId,
        ];
    }

    private function createTenant(
        string $tenantId,
        string $name,
    ): void {
        DB::table('tenants')->insert([
            'id' => $tenantId,
            'name' => $name,
            'subdomain' => sprintf(
                'guardian-student-%s',
                Str::lower(Str::random(12)),
            ),
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function createPerson(
        string $personId,
        string $name,
    ): void {
        DB::table('persons')->insert([
            'id' => $personId,
            'name' => $name,
            'status' => 'ACTIVE',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function createMembership(
        string $membershipId,
        string $personId,
        string $tenantId,
        string $status = 'ACTIVE',
    ): void {
        DB::table('memberships')->insert([
            'id' => $membershipId,
            'person_id' => $personId,
            'tenant_id' => $tenantId,
            'status' => $status,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function createClass(
        string $classId,
        string $tenantId,
        string $name,
    ): void {
        DB::table('academic_classes')->insert([
            'id' => $classId,
            'tenant_id' => $tenantId,
            'name' => $name,
            'code' => null,
            'tingkat' => '8',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function issueToken(): string
    {
        return app(TokenManagerInterface::class)
            ->issueToken(
                $this->operatorUserId,
                $this->tenantId,
                ['membership_id' => $this->operatorMembershipId],
            );
    }
}
