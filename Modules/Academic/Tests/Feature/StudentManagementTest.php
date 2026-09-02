<?php

declare(strict_types=1);

namespace Modules\Academic\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Mockery\MockInterface;
use Modules\Academic\Contracts\StudentRepositoryInterface;
use Modules\Academic\Database\Seeders\AcademicAuthorizationCatalogSeeder;
use Modules\Auth\Token\Contracts\TokenManagerInterface;
use Modules\Core\Support\Uuid\UuidV7;
use RuntimeException;
use Tests\Support\GrantsAuthorizationRole;
use Tests\TestCase;

final class StudentManagementTest extends TestCase
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

    public function test_store_atomically_provisions_person_membership_and_student_without_user_account(): void
    {
        $beforeUsers = DB::table('users')->count();
        $beforePersons = DB::table('persons')->count();
        $beforeMemberships = DB::table('memberships')->count();

        $response = $this
            ->withToken($this->issueToken())
            ->postJson(route('api.v1.academic.students.store', [], false), [
                'class_id' => $this->classId,
                'nama' => '  Ahmad Fauzan  ',
                'nis' => 'ST-2026-001',
                'nisn' => '1234567890',
            ]);

        $response
            ->assertCreated()
            ->assertJsonPath('status', 'success')
            ->assertJsonPath('data.nama', 'Ahmad Fauzan')
            ->assertJsonPath('data.class_id', $this->classId)
            ->assertJsonPath('data.nis', 'ST-2026-001')
            ->assertJsonPath('data.nisn', '1234567890')
            ->assertJsonPath('data.student_status', 'active')
            ->assertJsonPath('data.membership_status', 'ACTIVE');

        $studentId = (string) $response->json('data.student_id');
        $membershipId = (string) $response->json('data.membership_id');
        $personId = (string) $response->json('data.person_id');

        $this->assertTrue(UuidV7::validate($studentId));
        $this->assertTrue(UuidV7::validate($membershipId));
        $this->assertTrue(UuidV7::validate($personId));

        $this->assertSame(
            $beforeUsers,
            DB::table('users')->count(),
            'Student provisioning must not create a digital User account.',
        );
        $this->assertSame(
            $beforePersons + 1,
            DB::table('persons')->count(),
        );
        $this->assertSame(
            $beforeMemberships + 1,
            DB::table('memberships')->count(),
        );

        $this->assertDatabaseHas('persons', [
            'id' => $personId,
            'name' => 'Ahmad Fauzan',
            'status' => 'ACTIVE',
        ]);
        $this->assertDatabaseHas('memberships', [
            'id' => $membershipId,
            'person_id' => $personId,
            'tenant_id' => $this->tenantId,
            'status' => 'ACTIVE',
        ]);
        $this->assertDatabaseHas('students', [
            'id' => $studentId,
            'tenant_id' => $this->tenantId,
            'membership_id' => $membershipId,
            'class_id' => $this->classId,
            'nis' => 'ST-2026-001',
            'nisn' => '1234567890',
            'status' => 'active',
        ]);

        $this->assertFalse(Schema::hasColumn('students', 'person_id'));
        $this->assertFalse(Schema::hasColumn('students', 'name'));
        $this->assertFalse(Schema::hasColumn('students', 'gender'));
        $this->assertFalse(Schema::hasColumn('students', 'birth_place'));
        $this->assertFalse(Schema::hasColumn('students', 'birth_date'));
        $this->assertFalse(Schema::hasColumn('students', 'address'));
    }

    public function test_store_rejects_class_from_another_tenant_without_partial_identity(): void
    {
        $otherTenantId = UuidV7::generate();
        $otherClassId = UuidV7::generate();

        $this->createTenant(
            $otherTenantId,
            'Other Student Tenant',
        );
        $this->createClass(
            $otherClassId,
            $otherTenantId,
            'Other Tenant Class',
        );

        $beforePersons = DB::table('persons')->count();
        $beforeMemberships = DB::table('memberships')->count();
        $beforeStudents = DB::table('students')->count();

        $this
            ->withToken($this->issueToken())
            ->postJson(route('api.v1.academic.students.store', [], false), [
                'class_id' => $otherClassId,
                'nama' => 'Cross Tenant Student',
                'nis' => 'ST-CROSS-001',
            ])
            ->assertNotFound();

        $this->assertSame($beforePersons, DB::table('persons')->count());
        $this->assertSame($beforeMemberships, DB::table('memberships')->count());
        $this->assertSame($beforeStudents, DB::table('students')->count());
    }

    public function test_student_repository_failure_rolls_back_person_and_membership(): void
    {
        $beforePersons = DB::table('persons')->count();
        $beforeMemberships = DB::table('memberships')->count();
        $beforeStudents = DB::table('students')->count();

        $this->mock(
            StudentRepositoryInterface::class,
            function (MockInterface $mock): void {
                $mock->shouldReceive('createProfileForTenant')
                    ->once()
                    ->andThrow(new RuntimeException('forced student persistence failure'));
            },
        );

        $this
            ->withToken($this->issueToken())
            ->postJson(route('api.v1.academic.students.store', [], false), [
                'class_id' => $this->classId,
                'nama' => 'Rollback Student',
                'nis' => 'ST-ROLLBACK-001',
            ])
            ->assertInternalServerError();

        $this->assertSame($beforePersons, DB::table('persons')->count());
        $this->assertSame($beforeMemberships, DB::table('memberships')->count());
        $this->assertSame($beforeStudents, DB::table('students')->count());
    }

    public function test_index_reads_name_from_person_and_does_not_expose_user_identity(): void
    {
        $created = $this
            ->withToken($this->issueToken())
            ->postJson(route('api.v1.academic.students.store', [], false), [
                'class_id' => $this->classId,
                'nama' => 'Canonical Student Name',
                'nis' => 'ST-LIST-001',
            ])
            ->assertCreated();

        $personId = (string) $created->json('data.person_id');

        DB::table('persons')
            ->where('id', $personId)
            ->update([
                'name' => 'Updated Person Name',
                'updated_at' => now(),
            ]);

        $response = $this
            ->withToken($this->issueToken())
            ->getJson(route('api.v1.academic.students.index', [], false));

        $response
            ->assertOk()
            ->assertJsonPath('data.0.nama', 'Updated Person Name')
            ->assertJsonPath('data.0.person_id', $personId)
            ->assertJsonPath('data.0.student_status', 'active')
            ->assertJsonPath('data.0.membership_status', 'ACTIVE');

        /** @var array<string, mixed> $student */
        $student = $response->json('data.0');

        $this->assertArrayNotHasKey('user_id', $student);
        $this->assertArrayNotHasKey('email', $student);
    }

    public function test_store_rejects_legacy_email_contract(): void
    {
        $this
            ->withToken($this->issueToken())
            ->postJson(route('api.v1.academic.students.store', [], false), [
                'class_id' => $this->classId,
                'nama' => 'Legacy Email Student',
                'email' => 'student@example.test',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['email']);
    }

    public function test_store_is_forbidden_when_registrar_role_is_revoked(): void
    {
        DB::table('membership_roles')
            ->where('membership_id', $this->operatorMembershipId)
            ->delete();

        $this
            ->withToken($this->issueToken())
            ->postJson(route('api.v1.academic.students.store', [], false), [
                'class_id' => $this->classId,
                'nama' => 'Unauthorized Student',
            ])
            ->assertForbidden();
    }

    public function test_index_is_forbidden_when_registrar_role_is_revoked(): void
    {
        DB::table('membership_roles')
            ->where('membership_id', $this->operatorMembershipId)
            ->delete();

        $this
            ->withToken($this->issueToken())
            ->getJson(route('api.v1.academic.students.index', [], false))
            ->assertForbidden();
    }

    private function createAuthenticatedTenantFixture(): void
    {
        $this->createTenant(
            $this->tenantId,
            'Canonical Student Tenant',
        );

        DB::table('persons')->insert([
            'id' => $this->operatorPersonId,
            'name' => 'Student Operator',
            'status' => 'ACTIVE',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('users')->insert([
            'id' => $this->operatorUserId,
            'person_id' => $this->operatorPersonId,
            'email' => sprintf(
                'student-operator-%s@educore.test',
                Str::lower(Str::random(10)),
            ),
            'password' => 'not-used-by-token-test',
            'status' => 'ACTIVE',
            'is_superadmin' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('memberships')->insert([
            'id' => $this->operatorMembershipId,
            'person_id' => $this->operatorPersonId,
            'tenant_id' => $this->tenantId,
            'status' => 'ACTIVE',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->createClass(
            $this->classId,
            $this->tenantId,
            'Canonical Student Class',
        );

        $this->grantRole(
            $this->operatorMembershipId,
            AcademicAuthorizationCatalogSeeder::REGISTRAR_ROLE,
        );
    }

    private function createTenant(
        string $tenantId,
        string $name,
    ): void {
        DB::table('tenants')->insert([
            'id' => $tenantId,
            'name' => $name,
            'subdomain' => sprintf(
                'student-%s',
                Str::lower(Str::random(12)),
            ),
            'is_active' => true,
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
