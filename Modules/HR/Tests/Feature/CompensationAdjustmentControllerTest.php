<?php

declare(strict_types=1);

namespace Modules\HR\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Auth\Token\Contracts\TokenManagerInterface;
use Modules\Core\Support\Uuid\UuidV7;
use Modules\Core\Tenancy\Contracts\TenantContextInterface;
use Modules\HR\Database\Seeders\HrAuthorizationCatalogSeeder;
use Modules\HR\Models\CompensationAdjustment;
use Modules\HR\Models\Employment;
use Symfony\Component\HttpFoundation\Response;
use Tests\Support\GrantsAuthorizationRole;
use Tests\Support\GrantsSubscriptionFeature;
use Tests\TestCase;

final class CompensationAdjustmentControllerTest extends TestCase
{
    use GrantsAuthorizationRole;
    use GrantsSubscriptionFeature;
    use RefreshDatabase;

    private string $tenantId;

    private string $operatorUserId;

    private string $operatorMembershipId;

    private string $secondOperatorUserId;

    private string $secondOperatorMembershipId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(HrAuthorizationCatalogSeeder::class);

        $this->tenantId = UuidV7::generate();
        $this->operatorUserId = UuidV7::generate();
        $this->operatorMembershipId = UuidV7::generate();
        $this->secondOperatorUserId = UuidV7::generate();
        $this->secondOperatorMembershipId = UuidV7::generate();

        $this->createTenantFixture();
        $this->grantTenantFeature($this->tenantId, 'hr_module');
        $this->createOperatorFixture(
            $this->operatorUserId,
            $this->operatorMembershipId,
            'Compensation Adjustment Operator One',
        );
        $this->createOperatorFixture(
            $this->secondOperatorUserId,
            $this->secondOperatorMembershipId,
            'Compensation Adjustment Operator Two',
        );

        $this->grantRole($this->operatorMembershipId, HrAuthorizationCatalogSeeder::HR_OFFICER_ROLE);
        $this->grantRole($this->secondOperatorMembershipId, HrAuthorizationCatalogSeeder::HR_OFFICER_ROLE);

        app(TenantContextInterface::class)->clear();
    }

    protected function tearDown(): void
    {
        app(TenantContextInterface::class)->clear();

        parent::tearDown();
    }

    public function test_store_creates_draft_adjustment(): void
    {
        $employmentId = $this->createActiveEmploymentFixture();

        $response = $this
            ->withToken($this->issueToken($this->operatorUserId, $this->operatorMembershipId))
            ->postJson(
                route(
                    'api.v1.hr.employments.compensation-adjustments.store',
                    ['employmentId' => $employmentId],
                    false,
                ),
                $this->validPayload(),
            );

        $response->assertCreated();
        $response->assertJsonPath('data.status', 'DRAFT');
        $response->assertJsonPath('data.requested_by_membership_id', $this->operatorMembershipId);
    }

    public function test_store_is_forbidden_without_manage_permission(): void
    {
        DB::table('membership_roles')
            ->where('membership_id', $this->operatorMembershipId)
            ->delete();

        $employmentId = $this->createActiveEmploymentFixture();

        $response = $this
            ->withToken($this->issueToken($this->operatorUserId, $this->operatorMembershipId))
            ->postJson(
                route(
                    'api.v1.hr.employments.compensation-adjustments.store',
                    ['employmentId' => $employmentId],
                    false,
                ),
                $this->validPayload(),
            );

        $response->assertStatus(Response::HTTP_FORBIDDEN);
    }

    public function test_full_maker_checker_workflow_end_to_end(): void
    {
        $employmentId = $this->createActiveEmploymentFixture();

        // Maker (operator satu) membuat & submit.
        $createResponse = $this
            ->withToken($this->issueToken($this->operatorUserId, $this->operatorMembershipId))
            ->postJson(
                route(
                    'api.v1.hr.employments.compensation-adjustments.store',
                    ['employmentId' => $employmentId],
                    false,
                ),
                $this->validPayload(),
            );

        $adjustmentId = $createResponse->json('data.id');

        $this
            ->withToken($this->issueToken($this->operatorUserId, $this->operatorMembershipId))
            ->postJson(
                route(
                    'api.v1.hr.employments.compensation-adjustments.submit',
                    ['employmentId' => $employmentId, 'adjustmentId' => $adjustmentId],
                    false,
                ),
            )->assertOk()
            ->assertJsonPath('data.status', 'SUBMITTED');

        // Checker (operator DUA — berbeda dari maker) menyetujui.
        $approveResponse = $this
            ->withToken($this->issueToken($this->secondOperatorUserId, $this->secondOperatorMembershipId))
            ->postJson(
                route(
                    'api.v1.hr.employments.compensation-adjustments.approve',
                    ['employmentId' => $employmentId, 'adjustmentId' => $adjustmentId],
                    false,
                ),
            );

        $approveResponse->assertOk();
        $approveResponse->assertJsonPath('data.status', 'APPROVED');
        $approveResponse->assertJsonPath('data.approved_by_membership_id', $this->secondOperatorMembershipId);
    }

    public function test_approve_rejects_self_approval_via_http(): void
    {
        $employmentId = $this->createActiveEmploymentFixture();

        $createResponse = $this
            ->withToken($this->issueToken($this->operatorUserId, $this->operatorMembershipId))
            ->postJson(
                route(
                    'api.v1.hr.employments.compensation-adjustments.store',
                    ['employmentId' => $employmentId],
                    false,
                ),
                $this->validPayload(),
            );

        $adjustmentId = $createResponse->json('data.id');

        $this
            ->withToken($this->issueToken($this->operatorUserId, $this->operatorMembershipId))
            ->postJson(
                route(
                    'api.v1.hr.employments.compensation-adjustments.submit',
                    ['employmentId' => $employmentId, 'adjustmentId' => $adjustmentId],
                    false,
                ),
            )->assertOk();

        // Maker yang sama mencoba approve baris miliknya sendiri.
        $response = $this
            ->withToken($this->issueToken($this->operatorUserId, $this->operatorMembershipId))
            ->postJson(
                route(
                    'api.v1.hr.employments.compensation-adjustments.approve',
                    ['employmentId' => $employmentId, 'adjustmentId' => $adjustmentId],
                    false,
                ),
            );

        $response->assertStatus(Response::HTTP_CONFLICT);
    }

    public function test_approve_is_forbidden_without_approve_permission(): void
    {
        $employmentId = $this->createActiveEmploymentFixture();

        $createResponse = $this
            ->withToken($this->issueToken($this->operatorUserId, $this->operatorMembershipId))
            ->postJson(
                route(
                    'api.v1.hr.employments.compensation-adjustments.store',
                    ['employmentId' => $employmentId],
                    false,
                ),
                $this->validPayload(),
            );

        $adjustmentId = $createResponse->json('data.id');

        $this
            ->withToken($this->issueToken($this->operatorUserId, $this->operatorMembershipId))
            ->postJson(
                route(
                    'api.v1.hr.employments.compensation-adjustments.submit',
                    ['employmentId' => $employmentId, 'adjustmentId' => $adjustmentId],
                    false,
                ),
            )->assertOk();

        DB::table('membership_roles')
            ->where('membership_id', $this->secondOperatorMembershipId)
            ->delete();

        $response = $this
            ->withToken($this->issueToken($this->secondOperatorUserId, $this->secondOperatorMembershipId))
            ->postJson(
                route(
                    'api.v1.hr.employments.compensation-adjustments.approve',
                    ['employmentId' => $employmentId, 'adjustmentId' => $adjustmentId],
                    false,
                ),
            );

        $response->assertStatus(Response::HTTP_FORBIDDEN);
    }

    public function test_reject_transitions_to_rejected(): void
    {
        $employmentId = $this->createActiveEmploymentFixture();

        $createResponse = $this
            ->withToken($this->issueToken($this->operatorUserId, $this->operatorMembershipId))
            ->postJson(
                route(
                    'api.v1.hr.employments.compensation-adjustments.store',
                    ['employmentId' => $employmentId],
                    false,
                ),
                $this->validPayload(),
            );

        $adjustmentId = $createResponse->json('data.id');

        $this
            ->withToken($this->issueToken($this->operatorUserId, $this->operatorMembershipId))
            ->postJson(
                route(
                    'api.v1.hr.employments.compensation-adjustments.submit',
                    ['employmentId' => $employmentId, 'adjustmentId' => $adjustmentId],
                    false,
                ),
            )->assertOk();

        $response = $this
            ->withToken($this->issueToken($this->secondOperatorUserId, $this->secondOperatorMembershipId))
            ->postJson(
                route(
                    'api.v1.hr.employments.compensation-adjustments.reject',
                    ['employmentId' => $employmentId, 'adjustmentId' => $adjustmentId],
                    false,
                ),
            );

        $response->assertOk();
        $response->assertJsonPath('data.status', 'REJECTED');
    }

    public function test_cancel_transitions_draft_to_cancelled(): void
    {
        $employmentId = $this->createActiveEmploymentFixture();

        $createResponse = $this
            ->withToken($this->issueToken($this->operatorUserId, $this->operatorMembershipId))
            ->postJson(
                route(
                    'api.v1.hr.employments.compensation-adjustments.store',
                    ['employmentId' => $employmentId],
                    false,
                ),
                $this->validPayload(),
            );

        $adjustmentId = $createResponse->json('data.id');

        $response = $this
            ->withToken($this->issueToken($this->operatorUserId, $this->operatorMembershipId))
            ->postJson(
                route(
                    'api.v1.hr.employments.compensation-adjustments.cancel',
                    ['employmentId' => $employmentId, 'adjustmentId' => $adjustmentId],
                    false,
                ),
            );

        $response->assertOk();
        $response->assertJsonPath('data.status', 'CANCELLED');
    }

    public function test_index_lists_adjustments_for_employment(): void
    {
        $employmentId = $this->createActiveEmploymentFixture();

        $this
            ->withToken($this->issueToken($this->operatorUserId, $this->operatorMembershipId))
            ->postJson(
                route(
                    'api.v1.hr.employments.compensation-adjustments.store',
                    ['employmentId' => $employmentId],
                    false,
                ),
                $this->validPayload(),
            )->assertCreated();

        $response = $this
            ->withToken($this->issueToken($this->operatorUserId, $this->operatorMembershipId))
            ->getJson(
                route(
                    'api.v1.hr.employments.compensation-adjustments.index',
                    ['employmentId' => $employmentId],
                    false,
                ),
            );

        $response->assertOk();
        $this->assertCount(1, $response->json('data'));
    }

    /**
     * @return array<string, mixed>
     */
    private function validPayload(): array
    {
        return [
            'adjustment_type' => CompensationAdjustment::TYPE_ONE_TIME_EARNING,
            'amount' => '1000000',
            'currency_code' => 'IDR',
            'target_period_start' => '2026-01-01',
            'target_period_end' => '2026-01-31',
            'reason' => 'Uji coba HTTP layer penyesuaian kompensasi.',
            'idempotency_key' => 'ADJ-HTTP-'.Str::upper(Str::random(12)),
        ];
    }

    private function issueToken(string $userId, string $membershipId): string
    {
        return app(TokenManagerInterface::class)
            ->issueToken(
                $userId,
                $this->tenantId,
                ['membership_id' => $membershipId],
            );
    }

    private function createTenantFixture(): void
    {
        DB::table('tenants')->insert([
            'id' => $this->tenantId,
            'name' => 'Compensation Adjustment Controller Tenant',
            'subdomain' => sprintf(
                'compensation-adjustment-ctrl-%s',
                Str::lower(Str::random(12)),
            ),
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function createOperatorFixture(
        string $userId,
        string $membershipId,
        string $name,
    ): void {
        $personId = UuidV7::generate();

        DB::table('persons')->insert([
            'id' => $personId,
            'name' => $name,
            'status' => 'ACTIVE',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('users')->insert([
            'id' => $userId,
            'person_id' => $personId,
            'email' => sprintf(
                'compensation-adjustment-ctrl-%s@educore.test',
                Str::lower(Str::random(10)),
            ),
            'password' => bcrypt('secret123'),
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
    }

    private function createActiveEmploymentFixture(): string
    {
        $employeePersonId = UuidV7::generate();

        DB::table('persons')->insert([
            'id' => $employeePersonId,
            'name' => 'Compensation Adjustment Fixture Employee',
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
