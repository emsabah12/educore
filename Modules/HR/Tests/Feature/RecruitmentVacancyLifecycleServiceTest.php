<?php

declare(strict_types=1);

namespace Modules\HR\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Core\Support\Uuid\UuidV7;
use Modules\Core\Tenancy\Contracts\TenantContextInterface;
use Modules\Core\Tenancy\Models\Tenant;
use Modules\HR\Exceptions\RecruitmentLifecycleException;
use Modules\HR\Models\Position;
use Modules\HR\Models\RecruitmentVacancy;
use Modules\HR\Services\RecruitmentVacancyLifecycleService;
use Tests\TestCase;

final class RecruitmentVacancyLifecycleServiceTest extends TestCase
{
    use RefreshDatabase;

    private RecruitmentVacancyLifecycleService $service;
    private string $tenantId;
    private string $membershipId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = new RecruitmentVacancyLifecycleService();
        $this->tenantId = $this->createTenant();
        $this->activateTenantContext($this->tenantId);
        $this->membershipId = $this->createMembership();
    }

    protected function tearDown(): void
    {
        app(TenantContextInterface::class)->clear();

        parent::tearDown();
    }

    public function test_create_draft_creates_vacancy_with_draft_status(): void
    {
        $positionId = $this->createPosition();
        $organizationId = $this->createOrganization();

        $vacancy = $this->service->createDraft($this->tenantId, [
            'code' => 'VAC-SVC-001',
            'title' => 'Guru Matematika',
            'position_id' => $positionId,
            'organization_id' => $organizationId,
            'requested_headcount' => 1,
            'created_by_membership_id' => $this->membershipId,
        ]);

        $this->assertSame(RecruitmentVacancy::STATUS_DRAFT, $vacancy->status);
    }

    public function test_create_draft_rejects_inactive_position(): void
    {
        $inactivePositionId = $this->createPosition(isActive: false);
        $organizationId = $this->createOrganization();

        $this->expectException(RecruitmentLifecycleException::class);
        $this->expectExceptionMessageMatches('/active catalog entry/');

        $this->service->createDraft($this->tenantId, [
            'code' => 'VAC-SVC-002',
            'title' => 'Guru Matematika',
            'position_id' => $inactivePositionId,
            'organization_id' => $organizationId,
            'requested_headcount' => 1,
            'created_by_membership_id' => $this->membershipId,
        ]);
    }

    public function test_create_draft_rejects_unit_not_belonging_to_organization(): void
    {
        $positionId = $this->createPosition();
        $organizationId = $this->createOrganization();
        $otherOrganizationId = $this->createOrganization();
        $unitFromOtherOrganization = $this->createOrganizationUnit($otherOrganizationId);

        $this->expectException(RecruitmentLifecycleException::class);
        $this->expectExceptionMessageMatches('/not an active unit of Organization/');

        $this->service->createDraft($this->tenantId, [
            'code' => 'VAC-SVC-003',
            'title' => 'Guru Matematika',
            'position_id' => $positionId,
            'organization_id' => $organizationId,
            'organization_unit_id' => $unitFromOtherOrganization,
            'requested_headcount' => 1,
            'created_by_membership_id' => $this->membershipId,
        ]);
    }

    public function test_submit_transitions_draft_to_pending_approval(): void
    {
        $vacancy = $this->createDraftVacancy();

        $submitted = $this->service->submit($this->tenantId, $vacancy->id);

        $this->assertSame(RecruitmentVacancy::STATUS_PENDING_APPROVAL, $submitted->status);
    }

    public function test_submit_rejects_non_draft_vacancy(): void
    {
        $vacancy = $this->createDraftVacancy();
        $this->service->submit($this->tenantId, $vacancy->id);

        $this->expectException(RecruitmentLifecycleException::class);
        $this->expectExceptionMessageMatches('/cannot be submitted from status \[PENDING_APPROVAL\]/');

        $this->service->submit($this->tenantId, $vacancy->id);
    }

    public function test_approve_transitions_pending_approval_to_approved_and_records_decision(): void
    {
        $vacancy = $this->createDraftVacancy();
        $this->service->submit($this->tenantId, $vacancy->id);

        $approved = $this->service->approve(
            $this->tenantId,
            $vacancy->id,
            $this->membershipId,
            'Kebutuhan mendesak.',
        );

        $this->assertSame(RecruitmentVacancy::STATUS_APPROVED, $approved->status);
        $this->assertCount(1, $approved->decisions);
        $this->assertSame('APPROVED', $approved->decisions->first()->decision);
    }

    public function test_reject_transitions_pending_approval_back_to_draft_and_records_decision(): void
    {
        $vacancy = $this->createDraftVacancy();
        $this->service->submit($this->tenantId, $vacancy->id);

        $rejected = $this->service->reject(
            $this->tenantId,
            $vacancy->id,
            $this->membershipId,
            'Anggaran belum tersedia.',
        );

        $this->assertSame(RecruitmentVacancy::STATUS_DRAFT, $rejected->status);
        $this->assertCount(1, $rejected->decisions);
        $this->assertSame('REJECTED', $rejected->decisions->first()->decision);
    }

    public function test_open_transitions_approved_to_open_and_sets_open_at(): void
    {
        $vacancy = $this->createDraftVacancy();
        $this->service->submit($this->tenantId, $vacancy->id);
        $this->service->approve($this->tenantId, $vacancy->id, $this->membershipId);

        $opened = $this->service->open($this->tenantId, $vacancy->id);

        $this->assertSame(RecruitmentVacancy::STATUS_OPEN, $opened->status);
        $this->assertNotNull($opened->open_at);
    }

    public function test_open_rejects_non_approved_vacancy(): void
    {
        $vacancy = $this->createDraftVacancy();

        $this->expectException(RecruitmentLifecycleException::class);
        $this->expectExceptionMessageMatches('/cannot be opened from status \[DRAFT\]/');

        $this->service->open($this->tenantId, $vacancy->id);
    }

    public function test_close_transitions_open_to_closed_and_sets_close_at(): void
    {
        $vacancy = $this->createDraftVacancy();
        $this->service->submit($this->tenantId, $vacancy->id);
        $this->service->approve($this->tenantId, $vacancy->id, $this->membershipId);
        $this->service->open($this->tenantId, $vacancy->id);

        $closed = $this->service->close($this->tenantId, $vacancy->id);

        $this->assertSame(RecruitmentVacancy::STATUS_CLOSED, $closed->status);
        $this->assertNotNull($closed->close_at);
    }

    public function test_cancel_transitions_open_vacancy_to_cancelled(): void
    {
        $vacancy = $this->createDraftVacancy();
        $this->service->submit($this->tenantId, $vacancy->id);
        $this->service->approve($this->tenantId, $vacancy->id, $this->membershipId);
        $this->service->open($this->tenantId, $vacancy->id);

        $cancelled = $this->service->cancel($this->tenantId, $vacancy->id);

        $this->assertSame(RecruitmentVacancy::STATUS_CANCELLED, $cancelled->status);
    }

    public function test_cancel_rejects_closed_vacancy(): void
    {
        $vacancy = $this->createDraftVacancy();
        $this->service->submit($this->tenantId, $vacancy->id);
        $this->service->approve($this->tenantId, $vacancy->id, $this->membershipId);
        $this->service->open($this->tenantId, $vacancy->id);
        $this->service->close($this->tenantId, $vacancy->id);

        $this->expectException(RecruitmentLifecycleException::class);
        $this->expectExceptionMessageMatches('/cannot be cancelled from status \[CLOSED\]/');

        $this->service->cancel($this->tenantId, $vacancy->id);
    }

    private function createDraftVacancy(): RecruitmentVacancy
    {
        return $this->service->createDraft($this->tenantId, [
            'code' => 'VAC-SVC-' . Str::upper(Str::random(6)),
            'title' => 'Guru Matematika',
            'position_id' => $this->createPosition(),
            'organization_id' => $this->createOrganization(),
            'requested_headcount' => 1,
            'created_by_membership_id' => $this->membershipId,
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
            'name' => 'Vacancy Service Tenant',
            'subdomain' => sprintf(
                'vacancy-svc-%s',
                Str::lower(Str::random(12)),
            ),
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $tenantId;
    }

    private function createPosition(bool $isActive = true): string
    {
        return Position::create([
            'code' => 'POS-' . Str::upper(Str::random(6)),
            'name' => 'Posisi Uji Vacancy Service',
            'is_active' => $isActive,
        ])->id;
    }

    private function createOrganization(): string
    {
        $organizationId = UuidV7::generate();

        DB::table('organizations')->insert([
            'id' => $organizationId,
            'tenant_id' => $this->tenantId,
            'name' => 'Vacancy Service Fixture Organization',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $organizationId;
    }

    private function createOrganizationUnit(string $organizationId): string
    {
        $unitId = UuidV7::generate();

        DB::table('organization_units')->insert([
            'id' => $unitId,
            'tenant_id' => $this->tenantId,
            'organization_id' => $organizationId,
            'name' => 'Vacancy Service Fixture Unit',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $unitId;
    }

    private function createMembership(): string
    {
        $personId = UuidV7::generate();
        $membershipId = UuidV7::generate();

        DB::table('persons')->insert([
            'id' => $personId,
            'name' => 'Vacancy Service Fixture Actor',
            'status' => 'ACTIVE',
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

        return $membershipId;
    }
}
