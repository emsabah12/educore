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
use Modules\HR\Models\RecruitmentHiringDecision;
use Modules\HR\Models\RecruitmentVacancy;
use Tests\TestCase;

final class RecruitmentHiringDecisionPersistenceTest extends TestCase
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

    public function test_decision_can_be_recorded_and_relation_orders_by_most_recent(): void
    {
        $this->activateTenantContext($this->tenantAId);
        [$application, $membershipId] = $this->createSubmittedApplication();

        $first = RecruitmentHiringDecision::create([
            'application_id' => $application->id,
            'decision' => RecruitmentHiringDecision::DECISION_REJECTED,
            'decided_by_membership_id' => $membershipId,
            'reason' => 'Awal ditolak.',
            'decided_at' => now()->subDay(),
        ]);

        $second = RecruitmentHiringDecision::create([
            'application_id' => $application->id,
            'decision' => RecruitmentHiringDecision::DECISION_APPROVED,
            'decided_by_membership_id' => $membershipId,
            'reason' => 'Direvisi, disetujui.',
            'decided_at' => now(),
        ]);

        // Append-only: KEDUA baris tetap ada (bukan overwrite).
        $this->assertCount(2, $application->hiringDecisions);
        $this->assertSame($second->id, $application->hiringDecisions->first()->id);
        $this->assertSame($first->id, $application->hiringDecisions->last()->id);
    }

    public function test_check_constraint_rejects_unknown_decision_value(): void
    {
        $this->activateTenantContext($this->tenantAId);
        [$application, $membershipId] = $this->createSubmittedApplication();

        $this->expectException(QueryException::class);

        RecruitmentHiringDecision::create([
            'application_id' => $application->id,
            'decision' => 'MAYBE',
            'decided_by_membership_id' => $membershipId,
            'decided_at' => now(),
        ]);
    }

    public function test_composite_foreign_key_rejects_application_from_another_tenant(): void
    {
        $this->activateTenantContext($this->tenantBId);
        [$applicationFromTenantB, $membershipFromTenantB] = $this->createSubmittedApplication();

        $this->activateTenantContext($this->tenantAId);

        $this->expectException(QueryException::class);

        DB::table('recruitment_hiring_decisions')->insert([
            'id' => UuidV7::generate(),
            'tenant_id' => $this->tenantAId,
            'application_id' => $applicationFromTenantB->id,
            'decision' => 'APPROVED',
            'decided_by_membership_id' => $membershipFromTenantB,
            'decided_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
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
            'name' => 'Hiring Decision Tenant',
            'subdomain' => sprintf(
                'hiring-decision-%s',
                Str::lower(Str::random(12)),
            ),
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $tenantId;
    }

    /**
     * @return array{0: RecruitmentApplication, 1: string}
     */
    private function createSubmittedApplication(): array
    {
        $tenantId = (string) app(TenantContextInterface::class)->getCurrentTenantId();

        $positionId = Position::create([
            'code' => 'POS-' . Str::upper(Str::random(6)),
            'name' => 'Posisi Uji Hiring Decision',
            'is_active' => true,
        ])->id;

        $organizationId = UuidV7::generate();
        DB::table('organizations')->insert([
            'id' => $organizationId,
            'tenant_id' => $tenantId,
            'name' => 'Hiring Decision Fixture Organization',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $personId = UuidV7::generate();
        DB::table('persons')->insert([
            'id' => $personId,
            'name' => 'Hiring Decision Fixture Actor',
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
            'code' => 'VAC-HD-' . Str::upper(Str::random(6)),
            'title' => 'Guru Matematika',
            'position_id' => $positionId,
            'organization_id' => $organizationId,
            'requested_headcount' => 1,
            'created_by_membership_id' => $membershipId,
        ])->id;

        $candidateId = RecruitmentCandidate::create([
            'display_name' => 'Kandidat Uji Hiring Decision ' . Str::random(6),
        ])->id;

        $application = RecruitmentApplication::create([
            'vacancy_id' => $vacancyId,
            'candidate_id' => $candidateId,
            'submitted_at' => now(),
        ]);

        return [$application, $membershipId];
    }
}
