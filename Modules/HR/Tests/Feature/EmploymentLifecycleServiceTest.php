<?php

declare(strict_types=1);

namespace Modules\HR\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Core\Support\Uuid\UuidV7;
use Modules\Core\Tenancy\Contracts\TenantContextInterface;
use Modules\Core\Tenancy\Models\Tenant;
use Modules\HR\Exceptions\EmploymentLifecycleException;
use Modules\HR\Models\Employment;
use Modules\HR\Models\EmploymentType;
use Modules\HR\Services\EmploymentLifecycleService;
use Tests\TestCase;

final class EmploymentLifecycleServiceTest extends TestCase
{
    use RefreshDatabase;

    private EmploymentLifecycleService $service;
    private string $tenantId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = new EmploymentLifecycleService();
        $this->tenantId = $this->createTenant('Lifecycle Tenant');
        $this->activateTenantContext($this->tenantId);
    }

    protected function tearDown(): void
    {
        app(TenantContextInterface::class)->clear();

        parent::tearDown();
    }

    public function test_create_planned_creates_employment_with_planned_status(): void
    {
        $employeeId = $this->createEmployee($this->tenantId);
        $employmentTypeId = $this->createEmploymentType($this->tenantId);

        $employment = $this->service->createPlanned(
            tenantId: $this->tenantId,
            employeeId: $employeeId,
            data: [
                'employment_type_id' => $employmentTypeId,
                'start_date' => '2026-09-01',
            ],
        );

        $this->assertSame(Employment::STATUS_PLANNED, $employment->status);
        $this->assertSame($employeeId, $employment->employee_id);
        $this->assertSame($employmentTypeId, $employment->employment_type_id);
    }

    public function test_create_planned_rejects_inactive_membership(): void
    {
        $employeeId = $this->createEmployee($this->tenantId, membershipStatus: 'INACTIVE');

        $this->expectException(EmploymentLifecycleException::class);
        $this->expectExceptionMessageMatches('/not ACTIVE/');

        $this->service->createPlanned(
            tenantId: $this->tenantId,
            employeeId: $employeeId,
            data: ['start_date' => '2026-09-01'],
        );
    }

    public function test_create_planned_rejects_inactive_employment_type_catalog(): void
    {
        $employeeId = $this->createEmployee($this->tenantId);
        $inactiveTypeId = $this->createEmploymentType($this->tenantId, isActive: false);

        $this->expectException(EmploymentLifecycleException::class);
        $this->expectExceptionMessageMatches('/active catalog entry/');

        $this->service->createPlanned(
            tenantId: $this->tenantId,
            employeeId: $employeeId,
            data: [
                'employment_type_id' => $inactiveTypeId,
                'start_date' => '2026-09-01',
            ],
        );
    }

    public function test_activate_transitions_planned_employment_to_active(): void
    {
        $employeeId = $this->createEmployee($this->tenantId);
        $employmentTypeId = $this->createEmploymentType($this->tenantId);

        $planned = $this->service->createPlanned(
            tenantId: $this->tenantId,
            employeeId: $employeeId,
            data: [
                'employment_type_id' => $employmentTypeId,
                'start_date' => '2026-09-01',
            ],
        );

        $activated = $this->service->activate(
            tenantId: $this->tenantId,
            employmentId: $planned->id,
        );

        $this->assertSame(Employment::STATUS_ACTIVE, $activated->status);
    }

    public function test_activate_rejects_employment_that_is_not_planned(): void
    {
        $employeeId = $this->createEmployee($this->tenantId);
        $employmentTypeId = $this->createEmploymentType($this->tenantId);

        $planned = $this->service->createPlanned(
            tenantId: $this->tenantId,
            employeeId: $employeeId,
            data: [
                'employment_type_id' => $employmentTypeId,
                'start_date' => '2026-09-01',
            ],
        );
        $this->service->activate($this->tenantId, $planned->id);

        $this->expectException(EmploymentLifecycleException::class);
        $this->expectExceptionMessageMatches('/cannot be activated from status \[ACTIVE\]/');

        // Mencoba mengaktifkan Employment yang sudah ACTIVE.
        $this->service->activate($this->tenantId, $planned->id);
    }

    public function test_activate_rejects_employment_without_employment_type(): void
    {
        $employeeId = $this->createEmployee($this->tenantId);

        $planned = $this->service->createPlanned(
            tenantId: $this->tenantId,
            employeeId: $employeeId,
            data: ['start_date' => '2026-09-01'],
        );

        $this->expectException(EmploymentLifecycleException::class);
        $this->expectExceptionMessageMatches('/without an employment_type_id/');

        $this->service->activate($this->tenantId, $planned->id);
    }

    public function test_activate_rejects_second_active_employment_for_same_employee(): void
    {
        $employeeId = $this->createEmployee($this->tenantId);
        $employmentTypeId = $this->createEmploymentType($this->tenantId);

        $firstPlanned = $this->service->createPlanned(
            tenantId: $this->tenantId,
            employeeId: $employeeId,
            data: [
                'employment_type_id' => $employmentTypeId,
                'start_date' => '2020-01-01',
            ],
        );
        $this->service->activate($this->tenantId, $firstPlanned->id);

        $secondPlanned = $this->service->createPlanned(
            tenantId: $this->tenantId,
            employeeId: $employeeId,
            data: [
                'employment_type_id' => $employmentTypeId,
                'start_date' => '2026-01-01',
            ],
        );

        $this->expectException(EmploymentLifecycleException::class);
        $this->expectExceptionMessageMatches('/INV-HR-002/');

        $this->service->activate($this->tenantId, $secondPlanned->id);
    }

    public function test_cancel_transitions_planned_employment_to_cancelled(): void
    {
        $employeeId = $this->createEmployee($this->tenantId);

        $planned = $this->service->createPlanned(
            tenantId: $this->tenantId,
            employeeId: $employeeId,
            data: ['start_date' => '2026-09-01'],
        );

        $cancelled = $this->service->cancel(
            tenantId: $this->tenantId,
            employmentId: $planned->id,
        );

        $this->assertSame(Employment::STATUS_CANCELLED, $cancelled->status);
        $this->assertNotNull($cancelled->cancelled_at);
    }

    public function test_cancel_rejects_employment_that_is_not_planned(): void
    {
        $employeeId = $this->createEmployee($this->tenantId);
        $employmentTypeId = $this->createEmploymentType($this->tenantId);

        $planned = $this->service->createPlanned(
            tenantId: $this->tenantId,
            employeeId: $employeeId,
            data: [
                'employment_type_id' => $employmentTypeId,
                'start_date' => '2026-09-01',
            ],
        );
        $this->service->activate($this->tenantId, $planned->id);

        $this->expectException(EmploymentLifecycleException::class);
        $this->expectExceptionMessageMatches('/Only PLANNED employment may be cancelled/');

        $this->service->cancel($this->tenantId, $planned->id);
    }

    private function activateTenantContext(string $tenantId): void
    {
        /** @var Tenant $tenant */
        $tenant = Tenant::query()->findOrFail($tenantId);

        app(TenantContextInterface::class)->setCurrentTenant($tenant);
    }

    private function createTenant(string $name): string
    {
        $tenantId = UuidV7::generate();

        DB::table('tenants')->insert([
            'id' => $tenantId,
            'name' => $name,
            'subdomain' => sprintf(
                'lifecycle-%s',
                Str::lower(Str::random(12)),
            ),
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $tenantId;
    }

    private function createEmployee(
        string $tenantId,
        string $membershipStatus = 'ACTIVE',
    ): string {
        $personId = UuidV7::generate();
        $membershipId = UuidV7::generate();

        DB::table('persons')->insert([
            'id' => $personId,
            'name' => 'Lifecycle Fixture Person',
            'status' => 'ACTIVE',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('memberships')->insert([
            'id' => $membershipId,
            'person_id' => $personId,
            'tenant_id' => $tenantId,
            'status' => $membershipStatus,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $employeeId = UuidV7::generate();

        DB::table('employees')->insert([
            'id' => $employeeId,
            'tenant_id' => $tenantId,
            'membership_id' => $membershipId,
            'nip' => sprintf('NIP-%s', Str::upper(Str::random(8))),
            'jabatan' => 'GURU',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $employeeId;
    }

    private function createEmploymentType(
        string $tenantId,
        bool $isActive = true,
    ): string {
        return EmploymentType::create([
            'code' => 'TIPE-' . Str::upper(Str::random(6)),
            'name' => 'Tipe Kepegawaian Uji',
            'is_active' => $isActive,
        ])->id;
    }
}
