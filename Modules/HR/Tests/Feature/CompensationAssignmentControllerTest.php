<?php

declare(strict_types=1);

namespace Modules\HR\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Auth\Token\Contracts\TokenManagerInterface;
use Modules\Core\Support\Uuid\UuidV7;
use Modules\Core\Tenancy\Contracts\TenantContextInterface;
use Modules\Core\Tenancy\Models\Tenant;
use Modules\HR\Database\Seeders\HrAuthorizationCatalogSeeder;
use Modules\HR\Models\CompensationComponent;
use Modules\HR\Models\Employment;
use Symfony\Component\HttpFoundation\Response;
use Tests\Support\GrantsAuthorizationRole;
use Tests\Support\GrantsSubscriptionFeature;
use Tests\TestCase;

final class CompensationAssignmentControllerTest extends TestCase
{
    use RefreshDatabase;
    use GrantsAuthorizationRole;
    use GrantsSubscriptionFeature;

    private string $tenantId;
    private string $operatorUserId;
    private string $operatorMembershipId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(HrAuthorizationCatalogSeeder::class);

        $this->tenantId = UuidV7::generate();
        $this->operatorUserId = UuidV7::generate();
        $this->operatorMembershipId = UuidV7::generate();

        $this->createTenantFixture();
        $this->grantTenantFeature($this->tenantId, 'hr_module');
        $this->createOperatorFixture();

        $this->grantRole(
            $this->operatorMembershipId,
            HrAuthorizationCatalogSeeder::HR_OFFICER_ROLE,
        );

        app(TenantContextInterface::class)->clear();
    }

    protected function tearDown(): void
    {
        app(TenantContextInterface::class)->clear();

        parent::tearDown();
    }

    public function test_store_creates_draft_assignment(): void
    {
        $employmentId = $this->createActiveEmploymentFixture();
        $componentId = $this->createComponentFixture()->id;

        $response = $this
            ->withToken($this->issueToken())
            ->postJson(
                route(
                    'api.v1.hr.employments.compensation-assignments.store',
                    ['employmentId' => $employmentId],
                    false,
                ),
                [
                    'compensation_component_id' => $componentId,
                    'amount' => '5000000',
                    'currency_code' => 'IDR',
                    'effective_from' => '2026-01-01',
                ],
            );

        $response->assertCreated();
        $response->assertJsonPath('data.status', 'DRAFT');

        $this->assertDatabaseHas('compensation_assignments', [
            'employment_id' => $employmentId,
            'status' => 'DRAFT',
        ]);
    }

    public function test_store_is_forbidden_without_manage_permission(): void
    {
        DB::table('membership_roles')
            ->where('membership_id', $this->operatorMembershipId)
            ->delete();

        $employmentId = $this->createActiveEmploymentFixture();
        $componentId = $this->createComponentFixture()->id;

        $response = $this
            ->withToken($this->issueToken())
            ->postJson(
                route(
                    'api.v1.hr.employments.compensation-assignments.store',
                    ['employmentId' => $employmentId],
                    false,
                ),
                [
                    'compensation_component_id' => $componentId,
                    'amount' => '5000000',
                    'currency_code' => 'IDR',
                    'effective_from' => '2026-01-01',
                ],
            );

        $response->assertStatus(Response::HTTP_FORBIDDEN);
    }

    public function test_store_returns_not_found_for_unknown_component(): void
    {
        $employmentId = $this->createActiveEmploymentFixture();

        $response = $this
            ->withToken($this->issueToken())
            ->postJson(
                route(
                    'api.v1.hr.employments.compensation-assignments.store',
                    ['employmentId' => $employmentId],
                    false,
                ),
                [
                    'compensation_component_id' => UuidV7::generate(),
                    'amount' => '5000000',
                    'currency_code' => 'IDR',
                    'effective_from' => '2026-01-01',
                ],
            );

        $response->assertStatus(Response::HTTP_NOT_FOUND);
    }

    public function test_index_lists_assignments_for_employment(): void
    {
        $employmentId = $this->createActiveEmploymentFixture();
        $componentId = $this->createComponentFixture()->id;

        $this
            ->withToken($this->issueToken())
            ->postJson(
                route(
                    'api.v1.hr.employments.compensation-assignments.store',
                    ['employmentId' => $employmentId],
                    false,
                ),
                [
                    'compensation_component_id' => $componentId,
                    'amount' => '5000000',
                    'currency_code' => 'IDR',
                    'effective_from' => '2026-01-01',
                ],
            )->assertCreated();

        $response = $this
            ->withToken($this->issueToken())
            ->getJson(
                route(
                    'api.v1.hr.employments.compensation-assignments.index',
                    ['employmentId' => $employmentId],
                    false,
                ),
            );

        $response->assertOk();
        $this->assertCount(1, $response->json('data'));
    }

    public function test_approve_transitions_draft_to_approved_using_authenticated_membership(): void
    {
        $employmentId = $this->createActiveEmploymentFixture();
        $componentId = $this->createComponentFixture()->id;

        $draftResponse = $this
            ->withToken($this->issueToken())
            ->postJson(
                route(
                    'api.v1.hr.employments.compensation-assignments.store',
                    ['employmentId' => $employmentId],
                    false,
                ),
                [
                    'compensation_component_id' => $componentId,
                    'amount' => '5000000',
                    'currency_code' => 'IDR',
                    'effective_from' => '2026-01-01',
                ],
            );

        $assignmentId = $draftResponse->json('data.id');

        $response = $this
            ->withToken($this->issueToken())
            ->postJson(
                route(
                    'api.v1.hr.employments.compensation-assignments.approve',
                    ['employmentId' => $employmentId, 'assignmentId' => $assignmentId],
                    false,
                ),
            );

        $response->assertOk();
        $response->assertJsonPath('data.status', 'APPROVED');

        // Approver HARUS membership operator yang login (dari
        // authenticated_membership_id) — tidak pernah bisa dipalsukan
        // lewat body request (tidak ada field itu di route/request).
        $response->assertJsonPath('data.approved_by_membership_id', $this->operatorMembershipId);
    }

    public function test_approve_is_forbidden_without_approve_permission(): void
    {
        $employmentId = $this->createActiveEmploymentFixture();
        $componentId = $this->createComponentFixture()->id;

        $draftResponse = $this
            ->withToken($this->issueToken())
            ->postJson(
                route(
                    'api.v1.hr.employments.compensation-assignments.store',
                    ['employmentId' => $employmentId],
                    false,
                ),
                [
                    'compensation_component_id' => $componentId,
                    'amount' => '5000000',
                    'currency_code' => 'IDR',
                    'effective_from' => '2026-01-01',
                ],
            );

        $assignmentId = $draftResponse->json('data.id');

        DB::table('membership_roles')
            ->where('membership_id', $this->operatorMembershipId)
            ->delete();

        $response = $this
            ->withToken($this->issueToken())
            ->postJson(
                route(
                    'api.v1.hr.employments.compensation-assignments.approve',
                    ['employmentId' => $employmentId, 'assignmentId' => $assignmentId],
                    false,
                ),
            );

        $response->assertStatus(Response::HTTP_FORBIDDEN);
    }

    public function test_end_closes_approved_assignment(): void
    {
        $employmentId = $this->createActiveEmploymentFixture();
        $componentId = $this->createComponentFixture()->id;

        $draftResponse = $this
            ->withToken($this->issueToken())
            ->postJson(
                route(
                    'api.v1.hr.employments.compensation-assignments.store',
                    ['employmentId' => $employmentId],
                    false,
                ),
                [
                    'compensation_component_id' => $componentId,
                    'amount' => '5000000',
                    'currency_code' => 'IDR',
                    'effective_from' => '2026-01-01',
                ],
            );

        $assignmentId = $draftResponse->json('data.id');

        $this
            ->withToken($this->issueToken())
            ->postJson(
                route(
                    'api.v1.hr.employments.compensation-assignments.approve',
                    ['employmentId' => $employmentId, 'assignmentId' => $assignmentId],
                    false,
                ),
            )->assertOk();

        $response = $this
            ->withToken($this->issueToken())
            ->postJson(
                route(
                    'api.v1.hr.employments.compensation-assignments.end',
                    ['employmentId' => $employmentId, 'assignmentId' => $assignmentId],
                    false,
                ),
                ['end_date' => '2026-06-30'],
            );

        $response->assertOk();
        $response->assertJsonPath('data.status', 'ENDED');
        $response->assertJsonPath('data.effective_to', '2026-06-30');
    }

    public function test_correct_creates_replacement_and_supersedes_original(): void
    {
        $employmentId = $this->createActiveEmploymentFixture();
        $componentId = $this->createComponentFixture()->id;

        $draftResponse = $this
            ->withToken($this->issueToken())
            ->postJson(
                route(
                    'api.v1.hr.employments.compensation-assignments.store',
                    ['employmentId' => $employmentId],
                    false,
                ),
                [
                    'compensation_component_id' => $componentId,
                    'amount' => '5000000',
                    'currency_code' => 'IDR',
                    'effective_from' => '2026-01-01',
                ],
            );

        $originalId = $draftResponse->json('data.id');

        $this
            ->withToken($this->issueToken())
            ->postJson(
                route(
                    'api.v1.hr.employments.compensation-assignments.approve',
                    ['employmentId' => $employmentId, 'assignmentId' => $originalId],
                    false,
                ),
            )->assertOk();

        $response = $this
            ->withToken($this->issueToken())
            ->postJson(
                route(
                    'api.v1.hr.employments.compensation-assignments.correct',
                    ['employmentId' => $employmentId, 'assignmentId' => $originalId],
                    false,
                ),
                [
                    'compensation_component_id' => $componentId,
                    'amount' => '5500000',
                    'currency_code' => 'IDR',
                    'effective_from' => '2026-01-01',
                    'reason' => 'Koreksi nominal keliru.',
                ],
            );

        $response->assertOk();
        $response->assertJsonPath('data.status', 'DRAFT');
        $response->assertJsonPath('data.supersedes_assignment_id', $originalId);

        $this->assertDatabaseHas('compensation_assignments', [
            'id' => $originalId,
            'status' => 'SUPERSEDED',
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

    private function createTenantFixture(?string $tenantId = null): void
    {
        DB::table('tenants')->insert([
            'id' => $tenantId ?? $this->tenantId,
            'name' => 'Compensation Assignment Controller Tenant',
            'subdomain' => sprintf(
                'compensation-assignment-%s',
                Str::lower(Str::random(12)),
            ),
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function createOperatorFixture(): void
    {
        $personId = UuidV7::generate();

        DB::table('persons')->insert([
            'id' => $personId,
            'name' => 'Compensation Assignment Operator',
            'status' => 'ACTIVE',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('users')->insert([
            'id' => $this->operatorUserId,
            'person_id' => $personId,
            'email' => sprintf(
                'compensation-assignment-operator-%s@educore.test',
                Str::lower(Str::random(10)),
            ),
            'password' => bcrypt('secret123'),
            'status' => 'ACTIVE',
            'is_superadmin' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('memberships')->insert([
            'id' => $this->operatorMembershipId,
            'person_id' => $personId,
            'tenant_id' => $this->tenantId,
            'status' => 'ACTIVE',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function createComponentFixture(): CompensationComponent
    {
        app(TenantContextInterface::class)->clear();

        $tenant = Tenant::query()->findOrFail($this->tenantId);
        app(TenantContextInterface::class)->setCurrentTenant($tenant);

        $component = CompensationComponent::create([
            'code' => 'BASE_SALARY-' . Str::upper(Str::random(4)),
            'name' => 'Gaji Pokok',
            'category' => CompensationComponent::CATEGORY_BASE_PAY,
            'value_mode' => CompensationComponent::VALUE_MODE_FIXED_AMOUNT,
            'unit_code' => null,
            'periodicity' => 'MONTHLY',
            'is_active' => true,
        ]);

        app(TenantContextInterface::class)->clear();

        return $component;
    }

    private function createActiveEmploymentFixture(): string
    {
        $employeePersonId = UuidV7::generate();

        DB::table('persons')->insert([
            'id' => $employeePersonId,
            'name' => 'Compensation Assignment Fixture Employee',
            'status' => 'ACTIVE',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $employeeMembershipId = UuidV7::generate();

        DB::table('memberships')->insert([
            'id' => $employeeMembershipId,
            'person_id' => $employeePersonId,
            'tenant_id' => $this->tenantId,
            'status' => 'ACTIVE',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $employeeId = UuidV7::generate();

        DB::table('employees')->insert([
            'id' => $employeeId,
            'tenant_id' => $this->tenantId,
            'membership_id' => $employeeMembershipId,
            'nip' => sprintf('NIP-%s', Str::upper(Str::random(8))),
            'jabatan' => 'GURU',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $employmentId = UuidV7::generate();

        DB::table('employments')->insert([
            'id' => $employmentId,
            'tenant_id' => $this->tenantId,
            'employee_id' => $employeeId,
            'status' => Employment::STATUS_ACTIVE,
            'start_date' => '2026-01-01',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $employmentId;
    }
}
