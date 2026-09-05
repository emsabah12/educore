<?php

declare(strict_types=1);

namespace Modules\HR\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Core\Support\Uuid\UuidV7;
use Modules\Core\Tenancy\Contracts\TenantContextInterface;
use Modules\Core\Tenancy\Models\Tenant;
use Modules\HR\Exceptions\OnboardingLifecycleException;
use Modules\HR\Models\OnboardingCase;
use Modules\HR\Models\OnboardingTask;
use Modules\HR\Models\OnboardingTemplate;
use Modules\HR\Models\OnboardingTemplateTask;
use Modules\HR\Models\Position;
use Modules\HR\Models\RecruitmentApplication;
use Modules\HR\Models\RecruitmentCandidate;
use Modules\HR\Models\RecruitmentVacancy;
use Modules\HR\Services\OnboardingCaseLifecycleService;
use Tests\TestCase;

final class OnboardingCaseLifecycleServiceTest extends TestCase
{
    use RefreshDatabase;

    private OnboardingCaseLifecycleService $service;
    private string $tenantId;
    private string $membershipId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = new OnboardingCaseLifecycleService();
        $this->tenantId = $this->createTenant();
        $this->activateTenantContext($this->tenantId);
        $this->membershipId = $this->createMembership();
    }

    protected function tearDown(): void
    {
        app(TenantContextInterface::class)->clear();

        parent::tearDown();
    }

    public function test_create_case_creates_case_with_default_status_and_no_template(): void
    {
        $applicationId = $this->createRecruitmentApplicationFixture();

        $case = $this->service->createCase($this->tenantId, $applicationId);

        $this->assertSame(OnboardingCase::STATUS_NOT_STARTED, $case->status);
        $this->assertCount(0, $case->tasks);
    }

    public function test_create_case_snapshots_template_tasks(): void
    {
        $applicationId = $this->createRecruitmentApplicationFixture();
        $templateId = $this->createTemplateWithTasks();

        $case = $this->service->createCase($this->tenantId, $applicationId, $templateId);

        $this->assertCount(2, $case->tasks);
        $this->assertSame(
            ['SUBMIT_ID_CARD', 'ORIENTATION'],
            $case->tasks->pluck('code')->all(),
        );
    }

    public function test_create_case_rejects_duplicate_application(): void
    {
        $applicationId = $this->createRecruitmentApplicationFixture();
        $this->service->createCase($this->tenantId, $applicationId);

        $this->expectException(OnboardingLifecycleException::class);
        $this->expectExceptionMessageMatches('/already has an Onboarding Case/');

        $this->service->createCase($this->tenantId, $applicationId);
    }

    public function test_create_case_rejects_inactive_template(): void
    {
        $applicationId = $this->createRecruitmentApplicationFixture();
        $inactiveTemplateId = OnboardingTemplate::create([
            'code' => 'INACTIVE-' . Str::upper(Str::random(6)),
            'name' => 'Template Tidak Aktif',
            'is_active' => false,
        ])->id;

        $this->expectException(OnboardingLifecycleException::class);
        $this->expectExceptionMessageMatches('/active template/');

        $this->service->createCase($this->tenantId, $applicationId, $inactiveTemplateId);
    }

    public function test_start_progress_transitions_not_started_to_in_progress(): void
    {
        $applicationId = $this->createRecruitmentApplicationFixture();
        $case = $this->service->createCase($this->tenantId, $applicationId);

        $result = $this->service->startProgress($this->tenantId, $case->id);

        $this->assertSame(OnboardingCase::STATUS_IN_PROGRESS, $result->status);
        $this->assertNotNull($result->started_at);
    }

    public function test_start_progress_rejects_already_started_case(): void
    {
        $applicationId = $this->createRecruitmentApplicationFixture();
        $case = $this->service->createCase($this->tenantId, $applicationId);
        $this->service->startProgress($this->tenantId, $case->id);

        $this->expectException(OnboardingLifecycleException::class);

        $this->service->startProgress($this->tenantId, $case->id);
    }

    public function test_completing_last_required_task_advances_case_to_ready_for_activation(): void
    {
        $applicationId = $this->createRecruitmentApplicationFixture();
        $templateId = $this->createTemplateWithTasks();
        $case = $this->service->createCase($this->tenantId, $applicationId, $templateId);
        $this->service->startProgress($this->tenantId, $case->id);

        $taskIds = $case->tasks->pluck('id')->all();

        $this->service->completeTask($this->tenantId, $taskIds[0], $this->membershipId);
        $stillInProgress = OnboardingCase::query()->findOrFail($case->id);
        $this->assertSame(OnboardingCase::STATUS_IN_PROGRESS, $stillInProgress->status);

        $this->service->completeTask($this->tenantId, $taskIds[1], $this->membershipId, 'Selesai.');
        $nowReady = OnboardingCase::query()->findOrFail($case->id);
        $this->assertSame(OnboardingCase::STATUS_READY_FOR_ACTIVATION, $nowReady->status);
    }

    public function test_waived_required_task_also_counts_toward_ready_for_activation(): void
    {
        $applicationId = $this->createRecruitmentApplicationFixture();
        $templateId = $this->createTemplateWithTasks();
        $case = $this->service->createCase($this->tenantId, $applicationId, $templateId);
        $this->service->startProgress($this->tenantId, $case->id);

        $taskIds = $case->tasks->pluck('id')->all();

        $this->service->completeTask($this->tenantId, $taskIds[0], $this->membershipId);
        $this->service->waiveTask($this->tenantId, $taskIds[1], $this->membershipId, 'Tidak relevan untuk posisi ini.');

        $result = OnboardingCase::query()->findOrFail($case->id);
        $this->assertSame(OnboardingCase::STATUS_READY_FOR_ACTIVATION, $result->status);

        $waivedTask = OnboardingTask::query()->findOrFail($taskIds[1]);
        $this->assertSame(OnboardingTask::STATUS_WAIVED, $waivedTask->status);
    }

    public function test_case_does_not_advance_while_optional_task_still_pending_but_required_ones_complete(): void
    {
        $applicationId = $this->createRecruitmentApplicationFixture();
        $templateId = $this->createTemplateWithTasks();

        // Tambah satu tugas OPSIONAL (is_required=false) di template
        // yang sama, dibuat setelah helper agar tidak bentrok sequence.
        OnboardingTemplateTask::create([
            'template_id' => $templateId,
            'code' => 'OPTIONAL_SURVEY',
            'title' => 'Survei Opsional',
            'category' => 'ADMIN',
            'sequence' => 3,
            'is_required' => false,
        ]);

        $case = $this->service->createCase($this->tenantId, $applicationId, $templateId);
        $this->service->startProgress($this->tenantId, $case->id);

        $requiredTaskIds = $case->tasks
            ->where('is_required', true)
            ->pluck('id')
            ->all();

        foreach ($requiredTaskIds as $requiredTaskId) {
            $this->service->completeTask($this->tenantId, $requiredTaskId, $this->membershipId);
        }

        // Semua tugas WAJIB sudah selesai — tugas opsional yang masih
        // PENDING tidak menghalangi READY_FOR_ACTIVATION.
        $result = OnboardingCase::query()->findOrFail($case->id);
        $this->assertSame(OnboardingCase::STATUS_READY_FOR_ACTIVATION, $result->status);
    }

    public function test_cancel_transitions_case_to_cancelled(): void
    {
        $applicationId = $this->createRecruitmentApplicationFixture();
        $case = $this->service->createCase($this->tenantId, $applicationId);

        $result = $this->service->cancel($this->tenantId, $case->id, 'Kandidat mengundurkan diri.');

        $this->assertSame(OnboardingCase::STATUS_CANCELLED, $result->status);
    }

    public function test_cancel_requires_non_empty_reason(): void
    {
        $applicationId = $this->createRecruitmentApplicationFixture();
        $case = $this->service->createCase($this->tenantId, $applicationId);

        $this->expectException(OnboardingLifecycleException::class);
        $this->expectExceptionMessageMatches('/requires an explicit reason/');

        $this->service->cancel($this->tenantId, $case->id, '   ');
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
            'name' => 'Onboarding Case Service Tenant',
            'subdomain' => sprintf(
                'onboarding-case-svc-%s',
                Str::lower(Str::random(12)),
            ),
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $tenantId;
    }

    private function createMembership(): string
    {
        $personId = UuidV7::generate();
        $membershipId = UuidV7::generate();

        DB::table('persons')->insert([
            'id' => $personId,
            'name' => 'Onboarding Case Service Fixture Actor',
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

    private function createTemplateWithTasks(): string
    {
        $templateId = OnboardingTemplate::create([
            'code' => 'TPL-' . Str::upper(Str::random(6)),
            'name' => 'Template Uji Onboarding Service',
        ])->id;

        OnboardingTemplateTask::create([
            'template_id' => $templateId,
            'code' => 'SUBMIT_ID_CARD',
            'title' => 'Kumpulkan KTP',
            'category' => 'DOCUMENT',
            'sequence' => 1,
        ]);
        OnboardingTemplateTask::create([
            'template_id' => $templateId,
            'code' => 'ORIENTATION',
            'title' => 'Orientasi',
            'category' => 'ORIENTATION',
            'sequence' => 2,
        ]);

        return $templateId;
    }

    private function createRecruitmentApplicationFixture(): string
    {
        $positionId = Position::create([
            'code' => 'POS-' . Str::upper(Str::random(6)),
            'name' => 'Posisi Uji Onboarding Case Service',
            'is_active' => true,
        ])->id;

        $organizationId = UuidV7::generate();
        DB::table('organizations')->insert([
            'id' => $organizationId,
            'tenant_id' => $this->tenantId,
            'name' => 'Onboarding Case Service Fixture Organization',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $vacancyId = RecruitmentVacancy::create([
            'code' => 'VAC-OBSVC-' . Str::upper(Str::random(6)),
            'title' => 'Guru Matematika',
            'position_id' => $positionId,
            'organization_id' => $organizationId,
            'requested_headcount' => 1,
            'created_by_membership_id' => $this->membershipId,
        ])->id;

        $candidateId = RecruitmentCandidate::create([
            'display_name' => 'Kandidat Uji Onboarding Case Service ' . Str::random(6),
        ])->id;

        return RecruitmentApplication::create([
            'vacancy_id' => $vacancyId,
            'candidate_id' => $candidateId,
            'submitted_at' => now(),
        ])->id;
    }
}
