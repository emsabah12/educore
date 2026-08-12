<?php

declare(strict_types=1);

namespace Modules\HR\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Mockery\MockInterface;
use Modules\Auth\Token\Contracts\TokenManagerInterface;
use Modules\Core\Support\Uuid\UuidV7;
use Modules\Core\Tenancy\Contracts\TenantContextInterface;
use Modules\Core\Tenancy\Models\Tenant;
use Modules\HR\Contracts\EmployeeRepositoryInterface;
use Modules\HR\Models\Employee;
use Modules\HR\Repositories\EloquentEmployeeRepository;
use RuntimeException;
use Tests\TestCase;

final class EmployeeManagementTest extends TestCase
{
    use RefreshDatabase;

    private string $tenantId;
    private string $operatorPersonId;
    private string $operatorUserId;
    private string $operatorMembershipId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenantId = UuidV7::generate();
        $this->operatorPersonId = UuidV7::generate();
        $this->operatorUserId = UuidV7::generate();
        $this->operatorMembershipId = UuidV7::generate();

        $this->createAuthenticatedTenantFixture();
    }

    protected function tearDown(): void
    {
        app(TenantContextInterface::class)->clear();

        parent::tearDown();
    }

    public function test_store_atomically_provisions_person_membership_and_employee_without_user_account(): void
    {
        $beforeUsers = DB::table('users')->count();
        $beforePersons = DB::table('persons')->count();
        $beforeMemberships = DB::table('memberships')->count();

        $response = $this
            ->withToken($this->issueToken())
            ->postJson(route('api.v1.hr.employees.store', [], false), [
                'nama' => '  Guru HR Canonical  ',
                'nip' => '  EMP-2026-001  ',
                'jabatan' => '  guru  ',
            ]);

        $response
            ->assertCreated()
            ->assertJsonPath('status', 'success')
            ->assertJsonPath('data.nama', 'Guru HR Canonical')
            ->assertJsonPath('data.nip', 'EMP-2026-001')
            ->assertJsonPath('data.jabatan', 'GURU')
            ->assertJsonPath('data.membership_status', 'ACTIVE');

        $employeeId = (string) $response->json('data.employee_id');
        $membershipId = (string) $response->json('data.membership_id');
        $personId = (string) $response->json('data.person_id');

        $this->assertTrue(UuidV7::validate($employeeId));
        $this->assertTrue(UuidV7::validate($membershipId));
        $this->assertTrue(UuidV7::validate($personId));

        $this->assertSame(
            $beforeUsers,
            DB::table('users')->count(),
            'Employee provisioning must not create a digital User account.',
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
            'name' => 'Guru HR Canonical',
            'status' => 'ACTIVE',
        ]);
        $this->assertDatabaseHas('memberships', [
            'id' => $membershipId,
            'person_id' => $personId,
            'tenant_id' => $this->tenantId,
            'status' => 'ACTIVE',
        ]);
        $this->assertDatabaseHas('employees', [
            'id' => $employeeId,
            'tenant_id' => $this->tenantId,
            'membership_id' => $membershipId,
            'nip' => 'EMP-2026-001',
            'jabatan' => 'GURU',
        ]);

        /** @var array<string, mixed> $employee */
        $employee = $response->json('data');

        $this->assertArrayNotHasKey('user_id', $employee);
        $this->assertArrayNotHasKey('email', $employee);
        $this->assertArrayNotHasKey('status_aktif', $employee);

        $this->assertFalse(Schema::hasColumn('employees', 'person_id'));
        $this->assertTrue(Schema::hasColumn('employees', 'deleted_at'));

        $audit = DB::table('audit_logs')
            ->where('event_type', 'employee.created')
            ->latest('created_at')
            ->first();

        $this->assertNotNull($audit);

        /** @var array<string, mixed> $metadata */
        $metadata = json_decode(
            (string) $audit->metadata,
            true,
            512,
            JSON_THROW_ON_ERROR,
        );

        $this->assertSame($employeeId, $metadata['employee_id'] ?? null);
        $this->assertSame($membershipId, $metadata['membership_id'] ?? null);
        $this->assertSame($personId, $metadata['person_id'] ?? null);
        $this->assertArrayNotHasKey('nama', $metadata);
        $this->assertArrayNotHasKey('nip', $metadata);
        $this->assertArrayNotHasKey('email', $metadata);
    }

    public function test_repository_binding_and_employee_model_are_canonical(): void
    {
        $this->assertInstanceOf(
            EloquentEmployeeRepository::class,
            app(EmployeeRepositoryInterface::class),
        );

        $tenant = Tenant::query()->findOrFail($this->tenantId);
        app(TenantContextInterface::class)->setCurrentTenant($tenant);

        $employee = Employee::query()->create([
            'membership_id' => $this->operatorMembershipId,
            'nip' => 'MODEL-UUID-001',
            'jabatan' => 'STAFF',
        ]);

        $this->assertTrue(UuidV7::validate((string) $employee->id));
        $this->assertSame($this->tenantId, (string) $employee->tenant_id);
        $this->assertSame(
            $this->operatorMembershipId,
            (string) $employee->membership?->id,
        );
    }

    public function test_employee_repository_failure_rolls_back_person_and_membership(): void
    {
        $beforePersons = DB::table('persons')->count();
        $beforeMemberships = DB::table('memberships')->count();
        $beforeEmployees = DB::table('employees')->count();

        $this->mock(
            EmployeeRepositoryInterface::class,
            static function (MockInterface $mock): void {
                $mock->shouldReceive('createProfileForTenant')
                    ->once()
                    ->andThrow(new RuntimeException('forced employee persistence failure'));
            },
        );

        $this
            ->withToken($this->issueToken())
            ->postJson(route('api.v1.hr.employees.store', [], false), [
                'nama' => 'Rollback Employee',
                'nip' => 'ROLLBACK-001',
                'jabatan' => 'STAFF',
            ])
            ->assertInternalServerError()
            ->assertJsonPath('message', 'Failed to persist employee record.');

        $this->assertSame($beforePersons, DB::table('persons')->count());
        $this->assertSame($beforeMemberships, DB::table('memberships')->count());
        $this->assertSame($beforeEmployees, DB::table('employees')->count());
    }

    public function test_index_reads_name_from_person_and_excludes_soft_deleted_employee(): void
    {
        $created = $this
            ->withToken($this->issueToken())
            ->postJson(route('api.v1.hr.employees.store', [], false), [
                'nama' => 'Canonical Employee Name',
                'nip' => 'LIST-001',
                'jabatan' => 'STAFF',
            ])
            ->assertCreated();

        $employeeId = (string) $created->json('data.employee_id');
        $personId = (string) $created->json('data.person_id');

        DB::table('persons')
            ->where('id', $personId)
            ->update([
                'name' => 'Updated Employee Person Name',
                'updated_at' => now(),
            ]);

        $response = $this
            ->withToken($this->issueToken())
            ->getJson(route('api.v1.hr.employees.index', [], false));

        $response
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.nama', 'Updated Employee Person Name')
            ->assertJsonPath('data.0.person_id', $personId)
            ->assertJsonPath('data.0.membership_status', 'ACTIVE');

        /** @var array<string, mixed> $employee */
        $employee = $response->json('data.0');

        $this->assertArrayNotHasKey('user_id', $employee);
        $this->assertArrayNotHasKey('email', $employee);
        $this->assertArrayNotHasKey('status_aktif', $employee);

        DB::table('employees')
            ->where('id', $employeeId)
            ->update([
                'deleted_at' => now(),
                'updated_at' => now(),
            ]);

        $this
            ->withToken($this->issueToken())
            ->getJson(route('api.v1.hr.employees.index', [], false))
            ->assertOk()
            ->assertJsonPath('meta.total', 0)
            ->assertJsonCount(0, 'data');
    }

    public function test_nip_uniqueness_is_tenant_scoped(): void
    {
        $otherTenantId = UuidV7::generate();

        $this->createTenant(
            $otherTenantId,
            'Other Employee Tenant',
        );
        $this->createEmployeeProfileFixture(
            $otherTenantId,
            'SHARED-NIP-001',
        );

        $this
            ->withToken($this->issueToken())
            ->postJson(route('api.v1.hr.employees.store', [], false), [
                'nama' => 'Shared Nip Current Tenant',
                'nip' => 'SHARED-NIP-001',
                'jabatan' => 'GURU',
            ])
            ->assertCreated();

        $this
            ->withToken($this->issueToken())
            ->postJson(route('api.v1.hr.employees.store', [], false), [
                'nama' => 'Duplicate Current Tenant',
                'nip' => 'SHARED-NIP-001',
                'jabatan' => 'STAFF',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['nip']);

        $this->assertSame(
            2,
            DB::table('employees')
                ->where('nip', 'SHARED-NIP-001')
                ->count(),
        );
    }

    public function test_store_rejects_legacy_email_and_invalid_jabatan_contract(): void
    {
        $this
            ->withToken($this->issueToken())
            ->postJson(route('api.v1.hr.employees.store', [], false), [
                'nama' => 'Legacy Employee Contract',
                'nip' => 'LEGACY-001',
                'jabatan' => 'OWNER',
                'email' => 'employee@example.test',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors([
                'jabatan',
                'email',
            ]);
    }

    public function test_pagination_is_capped_at_one_hundred(): void
    {
        $response = $this
            ->withToken($this->issueToken())
            ->getJson(route(
                'api.v1.hr.employees.index',
                ['per_page' => 1000],
                false,
            ));

        $response
            ->assertOk()
            ->assertJsonPath('meta.per_page', 100);
    }

    private function createAuthenticatedTenantFixture(): void
    {
        $this->createTenant(
            $this->tenantId,
            'Canonical Employee Tenant',
        );

        DB::table('persons')->insert([
            'id' => $this->operatorPersonId,
            'name' => 'Employee Operator',
            'status' => 'ACTIVE',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('users')->insert([
            'id' => $this->operatorUserId,
            'person_id' => $this->operatorPersonId,
            'email' => sprintf(
                'employee-operator-%s@educore.test',
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
    }

    private function createTenant(
        string $tenantId,
        string $name,
    ): void {
        DB::table('tenants')->insert([
            'id' => $tenantId,
            'name' => $name,
            'subdomain' => sprintf(
                'employee-%s',
                Str::lower(Str::random(12)),
            ),
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function createEmployeeProfileFixture(
        string $tenantId,
        string $nip,
    ): void {
        $personId = UuidV7::generate();
        $membershipId = UuidV7::generate();

        DB::table('persons')->insert([
            'id' => $personId,
            'name' => 'Other Tenant Employee',
            'status' => 'ACTIVE',
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

        DB::table('employees')->insert([
            'id' => UuidV7::generate(),
            'tenant_id' => $tenantId,
            'membership_id' => $membershipId,
            'nip' => $nip,
            'jabatan' => 'STAFF',
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
