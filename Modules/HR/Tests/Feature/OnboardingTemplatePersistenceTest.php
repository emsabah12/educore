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
use Modules\HR\Models\OnboardingTemplate;
use Modules\HR\Models\OnboardingTemplateTask;
use Tests\TestCase;

final class OnboardingTemplatePersistenceTest extends TestCase
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

    public function test_template_can_be_created_with_default_active_flag(): void
    {
        $this->activateTenantContext($this->tenantAId);

        $template = OnboardingTemplate::create([
            'code' => 'STANDARD',
            'name' => 'Onboarding Guru Standar',
        ]);

        $this->assertTrue(Str::isUuid($template->id));
        $this->assertTrue($template->is_active);
    }

    public function test_template_code_must_be_unique_per_tenant(): void
    {
        $this->activateTenantContext($this->tenantAId);

        OnboardingTemplate::create(['code' => 'DUP', 'name' => 'Pertama']);

        $this->expectException(QueryException::class);

        OnboardingTemplate::create(['code' => 'DUP', 'name' => 'Kedua']);
    }

    public function test_same_code_is_allowed_across_different_tenants(): void
    {
        $this->activateTenantContext($this->tenantAId);
        OnboardingTemplate::create(['code' => 'SHARED', 'name' => 'Tenant A']);

        $this->activateTenantContext($this->tenantBId);
        $templateB = OnboardingTemplate::create(['code' => 'SHARED', 'name' => 'Tenant B']);

        $this->assertSame('Tenant B', $templateB->name);
    }

    public function test_task_can_be_created_with_default_flags(): void
    {
        $this->activateTenantContext($this->tenantAId);
        $templateId = $this->createTemplate();

        $task = OnboardingTemplateTask::create([
            'template_id' => $templateId,
            'code' => 'SUBMIT_ID_CARD',
            'title' => 'Kumpulkan KTP',
            'category' => OnboardingTemplateTask::CATEGORY_DOCUMENT,
            'sequence' => 1,
        ]);

        $this->assertTrue($task->is_required);
        $this->assertFalse($task->requires_evidence);
    }

    public function test_task_code_must_be_unique_per_template(): void
    {
        $this->activateTenantContext($this->tenantAId);
        $templateId = $this->createTemplate();

        OnboardingTemplateTask::create([
            'template_id' => $templateId,
            'code' => 'DUP',
            'title' => 'Tugas Pertama',
            'category' => OnboardingTemplateTask::CATEGORY_ADMIN,
            'sequence' => 1,
        ]);

        $this->expectException(QueryException::class);

        OnboardingTemplateTask::create([
            'template_id' => $templateId,
            'code' => 'DUP',
            'title' => 'Tugas Kedua',
            'category' => OnboardingTemplateTask::CATEGORY_ADMIN,
            'sequence' => 2,
        ]);
    }

    public function test_task_sequence_must_be_unique_per_template(): void
    {
        $this->activateTenantContext($this->tenantAId);
        $templateId = $this->createTemplate();

        OnboardingTemplateTask::create([
            'template_id' => $templateId,
            'code' => 'TASK_A',
            'title' => 'Tugas A',
            'category' => OnboardingTemplateTask::CATEGORY_ADMIN,
            'sequence' => 1,
        ]);

        $this->expectException(QueryException::class);

        OnboardingTemplateTask::create([
            'template_id' => $templateId,
            'code' => 'TASK_B',
            'title' => 'Tugas B',
            'category' => OnboardingTemplateTask::CATEGORY_ADMIN,
            'sequence' => 1,
        ]);
    }

    public function test_check_constraint_rejects_unknown_category(): void
    {
        $this->activateTenantContext($this->tenantAId);
        $templateId = $this->createTemplate();

        $this->expectException(QueryException::class);

        OnboardingTemplateTask::create([
            'template_id' => $templateId,
            'code' => 'BAD_CATEGORY',
            'title' => 'Tugas Aneh',
            'category' => 'UNKNOWN',
            'sequence' => 1,
        ]);
    }

    public function test_composite_foreign_key_rejects_template_from_another_tenant(): void
    {
        $this->activateTenantContext($this->tenantBId);
        $templateFromTenantB = $this->createTemplate();

        $this->activateTenantContext($this->tenantAId);

        $this->expectException(QueryException::class);

        OnboardingTemplateTask::create([
            'template_id' => $templateFromTenantB,
            'code' => 'CROSS',
            'title' => 'Tugas Lintas Tenant',
            'category' => OnboardingTemplateTask::CATEGORY_ADMIN,
            'sequence' => 1,
        ]);
    }

    public function test_template_tasks_relation_orders_by_sequence(): void
    {
        $this->activateTenantContext($this->tenantAId);
        $templateId = $this->createTemplate();

        OnboardingTemplateTask::create([
            'template_id' => $templateId,
            'code' => 'ORIENTATION',
            'title' => 'Orientasi',
            'category' => OnboardingTemplateTask::CATEGORY_ORIENTATION,
            'sequence' => 2,
        ]);
        OnboardingTemplateTask::create([
            'template_id' => $templateId,
            'code' => 'SUBMIT_ID_CARD',
            'title' => 'Kumpulkan KTP',
            'category' => OnboardingTemplateTask::CATEGORY_DOCUMENT,
            'sequence' => 1,
        ]);

        /** @var OnboardingTemplate $template */
        $template = OnboardingTemplate::query()->findOrFail($templateId);

        $this->assertSame(
            ['SUBMIT_ID_CARD', 'ORIENTATION'],
            $template->tasks->pluck('code')->all(),
        );
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
            'name' => 'Onboarding Template Tenant',
            'subdomain' => sprintf(
                'onboarding-template-%s',
                Str::lower(Str::random(12)),
            ),
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $tenantId;
    }

    private function createTemplate(): string
    {
        return OnboardingTemplate::create([
            'code' => 'TPL-' . Str::upper(Str::random(6)),
            'name' => 'Template Uji Onboarding',
        ])->id;
    }
}
