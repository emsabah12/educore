<?php

declare(strict_types=1);

namespace Modules\HR\Tests\Feature;

use Illuminate\Console\Command;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Auth\Token\Contracts\TokenManagerInterface;
use Modules\Core\Support\Uuid\UuidV7;
use Modules\Core\Tenancy\Contracts\TenantContextInterface;
use Modules\Core\Tenancy\Models\Tenant;
use Modules\HR\Database\Seeders\EmployeeSelfServiceRoleSeeder;
use Modules\HR\Database\Seeders\HrAuthorizationCatalogSeeder;
use Modules\HR\Models\LeaveType;
use Modules\HR\Services\EmployeeAccountProvisioningService;
use Modules\HR\Services\WorkspaceEmployeeProvisioningService;
use Tests\TestCase;

/**
 * §Langkah 4 (self-service leave gap remediation) — bukti end-to-end
 * bahwa gap HR-004 Phase 2C benar-benar tertutup, bukan cuma
 * administratif (baris di `membership_roles`), tapi juga fungsional:
 * pegawai yang di-assign role `employee` (baik otomatis lewat
 * WorkspaceEmployeeProvisioningService maupun lewat backfill) benar-
 * benar bisa memanggil endpoint self-service Cuti tanpa intervensi
 * admin apa pun.
 *
 * Test lain (EmployeeSelfServiceRoleSeederTest, bagian
 * WorkspaceEmployeeProvisioningServiceTest, dan
 * BackfillEmployeeSelfServiceRoleCommandTest) sudah memverifikasi
 * MEKANISME grant-nya secara terpisah (Langkah 1-3); file ini
 * memverifikasi hasil akhirnya dari sudut pandang pengguna
 * sesungguhnya, memakai jalur HTTP publik yang sama seperti yang
 * dipakai frontend.
 */
final class EmployeeSelfServiceLeaveAccessIntegrationTest extends TestCase
{
    use RefreshDatabase;

    private string $tenantId;

    private string $leaveTypeId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(HrAuthorizationCatalogSeeder::class);
        $this->seed(EmployeeSelfServiceRoleSeeder::class);

        $this->tenantId = UuidV7::generate();
        $this->createTenantFixture();
        $this->activateTenantContext($this->tenantId);

        $this->leaveTypeId = LeaveType::create([
            'code' => 'E2E-'.Str::upper(Str::random(6)),
            'name' => 'Izin Uji Integrasi End-to-End',
            'category' => LeaveType::CATEGORY_PERMIT,
            'balance_mode' => LeaveType::BALANCE_MODE_NONE,
            'unit' => LeaveType::UNIT_DAY,
        ])->id;
    }

    protected function tearDown(): void
    {
        app(TenantContextInterface::class)->clear();

        parent::tearDown();
    }

    public function test_newly_provisioned_employee_can_access_self_service_leave_without_any_manual_role_grant(): void
    {
        $organizationId = $this->createOrganization();
        $employmentTypeId = $this->createEmploymentType();

        // Persis jalur "Tambah Pegawai" sesungguhnya -- TIDAK ADA
        // langkah manual grant role apa pun di sini, cuma orkestrasi
        // yang sama persis dengan yang dipakai
        // WorkspaceEmployeeProvisioningController.
        $provisioned = app(WorkspaceEmployeeProvisioningService::class)
            ->provisionWithinWorkspace(
                tenantId: $this->tenantId,
                employeeData: [
                    'nama' => 'Pegawai Uji Integrasi Baru',
                    'nip' => 'NIP-E2E-'.Str::upper(Str::random(6)),
                    'jabatan' => 'GURU',
                    'employment_type_id' => $employmentTypeId,
                ],
                organizationId: $organizationId,
                organizationUnitId: null,
            );

        $account = app(EmployeeAccountProvisioningService::class)
            ->createAccountForEmployee(
                $provisioned['person_id'],
                sprintf('pegawai-e2e-%s@educore.test', Str::lower(Str::random(10))),
            );

        $token = $this->issueToken(
            $account['user_id'],
            $provisioned['membership_id'],
        );

        $balancesResponse = $this
            ->withToken($token)
            ->getJson(route('api.v1.hr.self.leave-balances.index', [], false));

        $balancesResponse->assertOk();

        $storeResponse = $this
            ->withToken($token)
            ->postJson(
                route('api.v1.hr.self.leave-requests.store', [], false),
                $this->requestPayload(),
            );

        $storeResponse->assertCreated();
    }

    public function test_pre_existing_employee_denied_before_backfill_is_granted_access_after_running_it(): void
    {
        // Simulasikan Employee yang dibuat SEBELUM
        // WorkspaceEmployeeProvisioningService mulai auto-assign role
        // employee -- insert manual langsung ke tabel, TANPA baris
        // membership_roles sama sekali (persis kondisi pegawai lama
        // di database production sebelum backfill dijalankan).
        $employee = $this->createLegacyEmployeeWithoutRole();

        $token = $this->issueToken(
            $employee['user_id'],
            $employee['membership_id'],
        );

        $deniedResponse = $this
            ->withToken($token)
            ->getJson(route('api.v1.hr.self.leave-balances.index', [], false));

        $deniedResponse->assertForbidden();

        $this
            ->artisan('hr:backfill-employee-self-service-role')
            ->assertExitCode(Command::SUCCESS);

        $grantedResponse = $this
            ->withToken($token)
            ->getJson(route('api.v1.hr.self.leave-balances.index', [], false));

        $grantedResponse->assertOk();

        $storeResponse = $this
            ->withToken($token)
            ->postJson(
                route('api.v1.hr.self.leave-requests.store', [], false),
                $this->requestPayload(),
            );

        $storeResponse->assertCreated();
    }

    private function requestPayload(): array
    {
        return [
            'leave_type_id' => $this->leaveTypeId,
            'starts_at' => '2026-06-01 00:00:00',
            'ends_at' => '2026-06-03 00:00:00',
            'request_timezone' => 'Asia/Jakarta',
            'requested_units' => '2',
        ];
    }

    private function createOrganization(): string
    {
        $organizationId = UuidV7::generate();

        DB::table('organizations')->insert([
            'id' => $organizationId,
            'tenant_id' => $this->tenantId,
            'name' => 'E2E Fixture Organization',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $organizationId;
    }

    private function createEmploymentType(): string
    {
        $employmentTypeId = UuidV7::generate();

        DB::table('employment_types')->insert([
            'id' => $employmentTypeId,
            'tenant_id' => $this->tenantId,
            'code' => 'TETAP-'.Str::upper(Str::random(6)),
            'name' => 'Pegawai Tetap',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $employmentTypeId;
    }

    /**
     * @return array{membership_id: string, user_id: string}
     */
    private function createLegacyEmployeeWithoutRole(): array
    {
        $personId = UuidV7::generate();
        $userId = UuidV7::generate();
        $membershipId = UuidV7::generate();
        $employeeId = UuidV7::generate();
        $employmentTypeId = UuidV7::generate();

        DB::table('persons')->insert([
            'id' => $personId,
            'name' => 'Pegawai Lama Sebelum Backfill',
            'status' => 'ACTIVE',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('users')->insert([
            'id' => $userId,
            'person_id' => $personId,
            'email' => sprintf('pegawai-lama-%s@educore.test', Str::lower(Str::random(10))),
            'password' => 'not-used-by-token-test',
            'status' => 'ACTIVE',
            'is_superadmin' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('memberships')->insert([
            'id' => $membershipId,
            'person_id' => $personId,
            'tenant_id' => $this->tenantId,
            'status' => 'ACTIVE',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('employees')->insert([
            'id' => $employeeId,
            'tenant_id' => $this->tenantId,
            'membership_id' => $membershipId,
            'nip' => 'NIP-LEGACY-'.Str::upper(Str::random(6)),
            'jabatan' => 'GURU',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // §Perbaikan test — LeaveSelfServiceController::balances()
        // butuh Employment ACTIVE terkait (bukan cuma role/permission)
        // untuk menghitung saldo, kalau tidak dilempar 409 lewat
        // noActiveEmploymentResponse() -- TIDAK ADA hubungannya
        // dengan role `employee`, jadi Employment ini WAJIB ada di
        // fixture supaya test murni menguji aspek otorisasi (403 vs
        // 200), bukan ikut tercampur gagal karena state lain.
        DB::table('employment_types')->insert([
            'id' => $employmentTypeId,
            'tenant_id' => $this->tenantId,
            'code' => 'LEGACY-'.Str::upper(Str::random(6)),
            'name' => 'Pegawai Tetap (Fixture Legacy)',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('employments')->insert([
            'id' => UuidV7::generate(),
            'tenant_id' => $this->tenantId,
            'employee_id' => $employeeId,
            'employment_type_id' => $employmentTypeId,
            'status' => 'ACTIVE',
            'start_date' => now()->toDateString(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return [
            'membership_id' => $membershipId,
            'user_id' => $userId,
        ];
    }

    private function activateTenantContext(string $tenantId): void
    {
        /** @var Tenant $tenant */
        $tenant = Tenant::query()->findOrFail($tenantId);

        app(TenantContextInterface::class)->setCurrentTenant($tenant);
    }

    private function createTenantFixture(): void
    {
        DB::table('tenants')->insert([
            'id' => $this->tenantId,
            'name' => 'Employee Self Service E2E Tenant',
            'subdomain' => sprintf('employee-self-service-e2e-%s', Str::lower(Str::random(12))),
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function issueToken(string $userId, string $membershipId): string
    {
        return app(TokenManagerInterface::class)
            ->issueToken($userId, $this->tenantId, ['membership_id' => $membershipId]);
    }
}
