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
use Modules\HR\Models\RecruitmentVacancy;
use Modules\HR\Models\RecruitmentVacancyDecision;
use Tests\TestCase;

final class RecruitmentVacancyPersistenceTest extends TestCase
{
    use RefreshDatabase;

    private string $tenantAId;
    private string $tenantBId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenantAId = $this->createTenant('Vacancy Tenant A');
        $this->tenantBId = $this->createTenant('Vacancy Tenant B');
    }

    protected function tearDown(): void
    {
        app(TenantContextInterface::class)->clear();

        parent::tearDown();
    }

    public function test_vacancy_can_be_created_with_default_draft_status(): void
    {
        $this->activateTenantContext($this->tenantAId);
        $positionId = $this->createPosition();
        $organizationId = $this->createOrganization($this->tenantAId);
        $membershipId = $this->createMembership($this->tenantAId);

        $vacancy = RecruitmentVacancy::create([
            'code' => 'VAC-001',
            'title' => 'Guru Matematika',
            'position_id' => $positionId,
            'organization_id' => $organizationId,
            'requested_headcount' => 2,
            'created_by_membership_id' => $membershipId,
        ]);

        $this->assertTrue(Str::isUuid($vacancy->id));
        $this->assertSame(RecruitmentVacancy::STATUS_DRAFT, $vacancy->status);
        $this->assertSame(2, $vacancy->requested_headcount);
    }

    public function test_vacancy_code_must_be_unique_per_tenant(): void
    {
        $this->activateTenantContext($this->tenantAId);
        $positionId = $this->createPosition();
        $organizationId = $this->createOrganization($this->tenantAId);
        $membershipId = $this->createMembership($this->tenantAId);

        $this->createVacancy($positionId, $organizationId, $membershipId, 'VAC-DUP');

        $this->expectException(QueryException::class);

        $this->createVacancy($positionId, $organizationId, $membershipId, 'VAC-DUP');
    }

    public function test_check_constraint_rejects_zero_headcount(): void
    {
        $this->activateTenantContext($this->tenantAId);
        $positionId = $this->createPosition();
        $organizationId = $this->createOrganization($this->tenantAId);
        $membershipId = $this->createMembership($this->tenantAId);

        $this->expectException(QueryException::class);

        RecruitmentVacancy::create([
            'code' => 'VAC-ZERO',
            'title' => 'Guru Matematika',
            'position_id' => $positionId,
            'organization_id' => $organizationId,
            'requested_headcount' => 0,
            'created_by_membership_id' => $membershipId,
        ]);
    }

    public function test_check_constraint_rejects_close_before_open(): void
    {
        $this->activateTenantContext($this->tenantAId);
        $positionId = $this->createPosition();
        $organizationId = $this->createOrganization($this->tenantAId);
        $membershipId = $this->createMembership($this->tenantAId);

        $this->expectException(QueryException::class);

        RecruitmentVacancy::create([
            'code' => 'VAC-DATE',
            'title' => 'Guru Matematika',
            'position_id' => $positionId,
            'organization_id' => $organizationId,
            'requested_headcount' => 1,
            'open_at' => '2026-06-01 00:00:00',
            'close_at' => '2026-01-01 00:00:00',
            'created_by_membership_id' => $membershipId,
        ]);
    }

    public function test_check_constraint_rejects_unknown_status_value(): void
    {
        $this->activateTenantContext($this->tenantAId);
        $positionId = $this->createPosition();
        $organizationId = $this->createOrganization($this->tenantAId);
        $membershipId = $this->createMembership($this->tenantAId);

        $this->expectException(QueryException::class);

        RecruitmentVacancy::create([
            'code' => 'VAC-BADSTATUS',
            'title' => 'Guru Matematika',
            'position_id' => $positionId,
            'organization_id' => $organizationId,
            'requested_headcount' => 1,
            'status' => 'UNKNOWN',
            'created_by_membership_id' => $membershipId,
        ]);
    }

    public function test_composite_foreign_key_rejects_position_from_another_tenant(): void
    {
        $this->activateTenantContext($this->tenantBId);
        $positionFromTenantB = $this->createPosition();

        $this->activateTenantContext($this->tenantAId);
        $organizationId = $this->createOrganization($this->tenantAId);
        $membershipId = $this->createMembership($this->tenantAId);

        $this->expectException(QueryException::class);

        RecruitmentVacancy::create([
            'code' => 'VAC-CROSS',
            'title' => 'Guru Matematika',
            'position_id' => $positionFromTenantB,
            'organization_id' => $organizationId,
            'requested_headcount' => 1,
            'created_by_membership_id' => $membershipId,
        ]);
    }

    public function test_composite_foreign_key_rejects_unit_not_belonging_to_organization(): void
    {
        $this->activateTenantContext($this->tenantAId);
        $positionId = $this->createPosition();
        $organizationId = $this->createOrganization($this->tenantAId);
        $otherOrganizationId = $this->createOrganization($this->tenantAId);
        $unitFromOtherOrganization = $this->createOrganizationUnit($this->tenantAId, $otherOrganizationId);
        $membershipId = $this->createMembership($this->tenantAId);

        $this->expectException(QueryException::class);

        RecruitmentVacancy::create([
            'code' => 'VAC-WRONGUNIT',
            'title' => 'Guru Matematika',
            'position_id' => $positionId,
            'organization_id' => $organizationId,
            'organization_unit_id' => $unitFromOtherOrganization,
            'requested_headcount' => 1,
            'created_by_membership_id' => $membershipId,
        ]);
    }

    public function test_decision_can_be_recorded_for_vacancy(): void
    {
        $this->activateTenantContext($this->tenantAId);
        $positionId = $this->createPosition();
        $organizationId = $this->createOrganization($this->tenantAId);
        $membershipId = $this->createMembership($this->tenantAId);
        $vacancy = $this->createVacancy($positionId, $organizationId, $membershipId, 'VAC-DECIDE');

        $decision = RecruitmentVacancyDecision::create([
            'vacancy_id' => $vacancy->id,
            'decision' => RecruitmentVacancyDecision::DECISION_APPROVED,
            'decided_by_membership_id' => $membershipId,
            'reason' => 'Kebutuhan mendesak.',
            'decided_at' => now(),
        ]);

        $this->assertSame(RecruitmentVacancyDecision::DECISION_APPROVED, $decision->decision);
        $this->assertCount(1, $vacancy->decisions);
    }

    public function test_check_constraint_rejects_unknown_decision_value(): void
    {
        $this->activateTenantContext($this->tenantAId);
        $positionId = $this->createPosition();
        $organizationId = $this->createOrganization($this->tenantAId);
        $membershipId = $this->createMembership($this->tenantAId);
        $vacancy = $this->createVacancy($positionId, $organizationId, $membershipId, 'VAC-BADDECISION');

        $this->expectException(QueryException::class);

        RecruitmentVacancyDecision::create([
            'vacancy_id' => $vacancy->id,
            'decision' => 'MAYBE',
            'decided_by_membership_id' => $membershipId,
            'decided_at' => now(),
        ]);
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
                'vacancy-%s',
                Str::lower(Str::random(12)),
            ),
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $tenantId;
    }

    private function createPosition(): string
    {
        return Position::create([
            'code' => 'POS-' . Str::upper(Str::random(6)),
            'name' => 'Posisi Uji Vacancy',
            'is_active' => true,
        ])->id;
    }

    private function createOrganization(string $tenantId): string
    {
        $organizationId = UuidV7::generate();

        DB::table('organizations')->insert([
            'id' => $organizationId,
            'tenant_id' => $tenantId,
            'name' => 'Vacancy Fixture Organization',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $organizationId;
    }

    private function createOrganizationUnit(string $tenantId, string $organizationId): string
    {
        $unitId = UuidV7::generate();

        DB::table('organization_units')->insert([
            'id' => $unitId,
            'tenant_id' => $tenantId,
            'organization_id' => $organizationId,
            'name' => 'Vacancy Fixture Unit',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $unitId;
    }

    private function createMembership(string $tenantId): string
    {
        $personId = UuidV7::generate();
        $membershipId = UuidV7::generate();

        DB::table('persons')->insert([
            'id' => $personId,
            'name' => 'Vacancy Fixture Actor',
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

        return $membershipId;
    }

    private function createVacancy(
        string $positionId,
        string $organizationId,
        string $membershipId,
        string $code,
    ): RecruitmentVacancy {
        return RecruitmentVacancy::create([
            'code' => $code,
            'title' => 'Guru Matematika',
            'position_id' => $positionId,
            'organization_id' => $organizationId,
            'requested_headcount' => 1,
            'created_by_membership_id' => $membershipId,
        ]);
    }
}
