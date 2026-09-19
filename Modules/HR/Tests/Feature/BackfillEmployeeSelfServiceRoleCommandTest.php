<?php

declare(strict_types=1);

namespace Modules\HR\Tests\Feature;

use Illuminate\Console\Command;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Core\Support\Uuid\UuidV7;
use Modules\HR\Database\Seeders\EmployeeSelfServiceRoleSeeder;
use Tests\TestCase;

final class BackfillEmployeeSelfServiceRoleCommandTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(EmployeeSelfServiceRoleSeeder::class);
    }

    public function test_command_assigns_role_to_active_employee_missing_it(): void
    {
        $tenantId = $this->createTenantFixture();
        $employee = $this->createEmployeeFixture($tenantId);

        $this
            ->artisan('hr:backfill-employee-self-service-role')
            ->assertExitCode(Command::SUCCESS);

        $this->assertDatabaseHas('membership_roles', [
            'membership_id' => $employee['membership_id'],
            'role_id' => $this->employeeRoleId(),
        ]);
    }

    public function test_command_skips_employee_that_already_has_the_role(): void
    {
        $tenantId = $this->createTenantFixture();
        $employee = $this->createEmployeeFixture($tenantId);

        DB::table('membership_roles')->insert([
            'membership_id' => $employee['membership_id'],
            'role_id' => $this->employeeRoleId(),
        ]);

        $this
            ->artisan('hr:backfill-employee-self-service-role')
            ->assertExitCode(Command::SUCCESS);

        $this->assertSame(
            1,
            DB::table('membership_roles')
                ->where('membership_id', $employee['membership_id'])
                ->where('role_id', $this->employeeRoleId())
                ->count(),
        );
    }

    public function test_command_skips_soft_deleted_employee(): void
    {
        $tenantId = $this->createTenantFixture();
        $employee = $this->createEmployeeFixture($tenantId);

        DB::table('employees')
            ->where('id', $employee['employee_id'])
            ->update([
                'deleted_at' => now(),
            ]);

        $this
            ->artisan('hr:backfill-employee-self-service-role')
            ->assertExitCode(Command::SUCCESS);

        $this->assertDatabaseMissing('membership_roles', [
            'membership_id' => $employee['membership_id'],
        ]);
    }

    public function test_command_skips_employee_with_inactive_membership(): void
    {
        $tenantId = $this->createTenantFixture();
        $employee = $this->createEmployeeFixture($tenantId);

        DB::table('memberships')
            ->where('id', $employee['membership_id'])
            ->update([
                'status' => 'INACTIVE',
            ]);

        $this
            ->artisan('hr:backfill-employee-self-service-role')
            ->assertExitCode(Command::SUCCESS);

        $this->assertDatabaseMissing('membership_roles', [
            'membership_id' => $employee['membership_id'],
        ]);
    }

    public function test_command_dry_run_does_not_modify_database(): void
    {
        $tenantId = $this->createTenantFixture();
        $employee = $this->createEmployeeFixture($tenantId);

        $this
            ->artisan(
                'hr:backfill-employee-self-service-role',
                ['--dry-run' => true],
            )
            ->assertExitCode(Command::SUCCESS);

        $this->assertDatabaseMissing('membership_roles', [
            'membership_id' => $employee['membership_id'],
        ]);
    }

    public function test_command_is_idempotent_across_repeated_runs(): void
    {
        $tenantId = $this->createTenantFixture();
        $employee = $this->createEmployeeFixture($tenantId);

        $this
            ->artisan('hr:backfill-employee-self-service-role')
            ->assertExitCode(Command::SUCCESS);

        $this
            ->artisan('hr:backfill-employee-self-service-role')
            ->assertExitCode(Command::SUCCESS);

        $this->assertSame(
            1,
            DB::table('membership_roles')
                ->where('membership_id', $employee['membership_id'])
                ->where('role_id', $this->employeeRoleId())
                ->count(),
        );
    }

    public function test_command_succeeds_when_no_employee_exists(): void
    {
        $this
            ->artisan('hr:backfill-employee-self-service-role')
            ->assertExitCode(Command::SUCCESS);

        $this->assertDatabaseCount('membership_roles', 0);
    }

    public function test_command_fails_closed_when_employee_role_is_not_seeded(): void
    {
        DB::table('role_permissions')
            ->whereIn(
                'role_id',
                DB::table('roles')->whereNull('tenant_id')->where('name', 'employee')->pluck('id'),
            )
            ->delete();

        DB::table('roles')
            ->whereNull('tenant_id')
            ->where('name', 'employee')
            ->delete();

        $this
            ->artisan('hr:backfill-employee-self-service-role')
            ->assertExitCode(Command::FAILURE);
    }

    public function test_command_processes_multiple_tenants_together(): void
    {
        $tenantOneId = $this->createTenantFixture();
        $employeeOne = $this->createEmployeeFixture($tenantOneId);

        $tenantTwoId = $this->createTenantFixture();
        $employeeTwo = $this->createEmployeeFixture($tenantTwoId);

        $this
            ->artisan('hr:backfill-employee-self-service-role')
            ->assertExitCode(Command::SUCCESS);

        $this->assertDatabaseHas('membership_roles', [
            'membership_id' => $employeeOne['membership_id'],
            'role_id' => $this->employeeRoleId(),
        ]);

        $this->assertDatabaseHas('membership_roles', [
            'membership_id' => $employeeTwo['membership_id'],
            'role_id' => $this->employeeRoleId(),
        ]);
    }

    private function employeeRoleId(): string
    {
        return (string) DB::table('roles')
            ->whereNull('tenant_id')
            ->where('name', 'employee')
            ->value('id');
    }

    private function createTenantFixture(): string
    {
        $tenantId = UuidV7::generate();

        DB::table('tenants')->insert([
            'id' => $tenantId,
            'name' => 'Backfill Employee Role Fixture Tenant',
            'subdomain' => sprintf(
                'backfill-employee-role-%s',
                Str::lower(Str::random(12)),
            ),
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $tenantId;
    }

    /**
     * @return array{employee_id: string, membership_id: string}
     */
    private function createEmployeeFixture(
        string $tenantId,
    ): array {
        $personId = UuidV7::generate();

        DB::table('persons')->insert([
            'id' => $personId,
            'name' => 'Backfill Employee Role Fixture Person',
            'status' => 'ACTIVE',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $membershipId = UuidV7::generate();

        DB::table('memberships')->insert([
            'id' => $membershipId,
            'person_id' => $personId,
            'tenant_id' => $tenantId,
            'status' => 'ACTIVE',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $employeeId = UuidV7::generate();

        DB::table('employees')->insert([
            'id' => $employeeId,
            'tenant_id' => $tenantId,
            'membership_id' => $membershipId,
            'nip' => 'NIP-BACKFILL-'.Str::upper(Str::random(6)),
            'jabatan' => 'GURU',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return [
            'employee_id' => $employeeId,
            'membership_id' => $membershipId,
        ];
    }
}
