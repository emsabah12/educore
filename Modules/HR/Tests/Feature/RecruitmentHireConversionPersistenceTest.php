<?php

declare(strict_types=1);

namespace Modules\HR\Tests\Feature;

use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Core\Support\Uuid\UuidV7;
use Modules\Core\Tenancy\Contracts\TenantContextInterface;
use Modules\Core\Tenancy\Models\Tenant;
use Modules\HR\Models\Position;
use Modules\HR\Models\RecruitmentApplication;
use Modules\HR\Models\RecruitmentCandidate;
use Modules\HR\Models\RecruitmentHireConversion;
use Modules\HR\Models\RecruitmentVacancy;
use Tests\TestCase;

final class RecruitmentHireConversionPersistenceTest extends TestCase
{
    use RefreshDatabase;

    private string $tenantAId;

    private string $tenantBId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenantAId = $this->createTenant();
        $this->tenantBId = $this->createTenant();
    }

    protected function tearDown(): void
    {
        app(TenantContextInterface::class)->clear();

        parent::tearDown();
    }

    public function test_conversion_can_be_created_with_default_statuses(): void
    {
        $this->activateTenantContext($this->tenantAId);
        $applicationId = $this->createApplicationFixture();

        $conversion = RecruitmentHireConversion::create([
            'application_id' => $applicationId,
        ]);

        $this->assertSame(RecruitmentHireConversion::RESOLUTION_UNRESOLVED, $conversion->resolution_status);
        $this->assertSame(RecruitmentHireConversion::CONVERSION_PENDING, $conversion->conversion_status);
    }

    public function test_database_rejects_duplicate_conversion_for_same_application(): void
    {
        $this->activateTenantContext($this->tenantAId);
        $applicationId = $this->createApplicationFixture();

        RecruitmentHireConversion::create(['application_id' => $applicationId]);

        $this->expectException(QueryException::class);

        RecruitmentHireConversion::create(['application_id' => $applicationId]);
    }

    public function test_check_constraint_rejects_unknown_resolution_status(): void
    {
        $this->activateTenantContext($this->tenantAId);
        $applicationId = $this->createApplicationFixture();

        $this->expectException(QueryException::class);

        RecruitmentHireConversion::create([
            'application_id' => $applicationId,
            'resolution_status' => 'UNKNOWN',
        ]);
    }

    public function test_check_constraint_rejects_unknown_conversion_status(): void
    {
        $this->activateTenantContext($this->tenantAId);
        $applicationId = $this->createApplicationFixture();

        $this->expectException(QueryException::class);

        RecruitmentHireConversion::create([
            'application_id' => $applicationId,
            'conversion_status' => 'UNKNOWN',
        ]);
    }

    /**
     * Constraint paling penting di tabel ini: SUCCEEDED tanpa seluruh
     * hasil (person/membership/employee/employment/converted_at) harus
     * mustahil secara struktural — bukan cuma disiplin kode di service
     * layer.
     */
    public function test_check_constraint_rejects_succeeded_without_full_results(): void
    {
        $this->activateTenantContext($this->tenantAId);
        $applicationId = $this->createApplicationFixture();

        $this->expectException(QueryException::class);

        RecruitmentHireConversion::create([
            'application_id' => $applicationId,
            'conversion_status' => RecruitmentHireConversion::CONVERSION_SUCCEEDED,
            // person_id, membership_id, employee_id, employment_id,
            // converted_at SENGAJA dibiarkan kosong.
        ]);
    }

    public function test_composite_foreign_key_rejects_application_from_another_tenant(): void
    {
        $this->activateTenantContext($this->tenantBId);
        $applicationFromTenantB = $this->createApplicationFixture();

        $this->activateTenantContext($this->tenantAId);

        $this->expectException(QueryException::class);

        RecruitmentHireConversion::create(['application_id' => $applicationFromTenantB]);
    }

    private function activateTenantContext(string $tenantId): void
    {
        /** @var Tenant $tenant */
        $tenant = Tenant::query()->findOrFail($tenantId);

        app(TenantContextInterface::class)->setCurrentTenant($tenant);
    }

    private function createTenant(): string
    {
        $tenantId = UuidV7::generate();

        DB::table('tenants')->insert([
            'id' => $tenantId,
            'name' => 'Hire Conversion Tenant',
            'subdomain' => sprintf(
                'hire-conversion-%s',
                Str::lower(Str::random(12)),
            ),
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $tenantId;
    }

    private function createApplicationFixture(): string
    {
        $tenantId = (string) app(TenantContextInterface::class)->getCurrentTenantId();

        $positionId = Position::create([
            'code' => 'POS-'.Str::upper(Str::random(6)),
            'name' => 'Posisi Uji Hire Conversion',
            'is_active' => true,
        ])->id;

        $organizationId = UuidV7::generate();
        DB::table('organizations')->insert([
            'id' => $organizationId,
            'tenant_id' => $tenantId,
            'name' => 'Hire Conversion Fixture Organization',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $personId = UuidV7::generate();
        DB::table('persons')->insert([
            'id' => $personId,
            'name' => 'Hire Conversion Fixture Actor',
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

        $vacancyId = RecruitmentVacancy::create([
            'code' => 'VAC-HC-'.Str::upper(Str::random(6)),
            'title' => 'Guru Matematika',
            'position_id' => $positionId,
            'organization_id' => $organizationId,
            'requested_headcount' => 1,
            'created_by_membership_id' => $membershipId,
        ])->id;

        $candidateId = RecruitmentCandidate::create([
            'display_name' => 'Kandidat Uji Hire Conversion '.Str::random(6),
        ])->id;

        return RecruitmentApplication::create([
            'vacancy_id' => $vacancyId,
            'candidate_id' => $candidateId,
            'submitted_at' => now(),
        ])->id;
    }
}
