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
use Modules\HR\Models\CompensationComponent;
use Symfony\Component\HttpFoundation\Response;
use Tests\Support\GrantsAuthorizationRole;
use Tests\Support\GrantsSubscriptionFeature;
use Tests\TestCase;

final class CompensationComponentControllerTest extends TestCase
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

    public function test_index_lists_components_for_current_tenant(): void
    {
        $this->createComponentFixture($this->tenantId, 'BASE_SALARY');

        $otherTenantId = UuidV7::generate();
        $this->createTenantFixture($otherTenantId);
        $this->createComponentFixture($otherTenantId, 'BASE_SALARY_OTHER');

        $response = $this
            ->withToken($this->issueToken())
            ->getJson(
                route('api.v1.hr.compensation.components.index', [], false),
            );

        $response->assertOk();

        $codes = collect($response->json('data'))->pluck('code');

        $this->assertTrue($codes->contains('BASE_SALARY'));
        $this->assertFalse(
            $codes->contains('BASE_SALARY_OTHER'),
            'Component belonging to another tenant must never appear.',
        );
    }

    public function test_index_is_forbidden_without_permission(): void
    {
        DB::table('membership_roles')
            ->where('membership_id', $this->operatorMembershipId)
            ->delete();

        $response = $this
            ->withToken($this->issueToken())
            ->getJson(
                route('api.v1.hr.compensation.components.index', [], false),
            );

        $response->assertStatus(Response::HTTP_FORBIDDEN);
    }

    public function test_store_creates_new_component(): void
    {
        $response = $this
            ->withToken($this->issueToken())
            ->postJson(
                route('api.v1.hr.compensation.components.store', [], false),
                [
                    'code' => 'BASE_SALARY',
                    'name' => 'Gaji Pokok',
                    'category' => CompensationComponent::CATEGORY_BASE_PAY,
                    'value_mode' => CompensationComponent::VALUE_MODE_FIXED_AMOUNT,
                    'periodicity' => 'MONTHLY',
                ],
            );

        $response->assertCreated();

        $this->assertDatabaseHas('compensation_components', [
            'tenant_id' => $this->tenantId,
            'code' => 'BASE_SALARY',
        ]);
    }

    public function test_store_is_forbidden_without_manage_permission(): void
    {
        DB::table('membership_roles')
            ->where('membership_id', $this->operatorMembershipId)
            ->delete();

        $response = $this
            ->withToken($this->issueToken())
            ->postJson(
                route('api.v1.hr.compensation.components.store', [], false),
                [
                    'code' => 'BASE_SALARY',
                    'name' => 'Gaji Pokok',
                    'category' => CompensationComponent::CATEGORY_BASE_PAY,
                    'value_mode' => CompensationComponent::VALUE_MODE_FIXED_AMOUNT,
                    'periodicity' => 'MONTHLY',
                ],
            );

        $response->assertStatus(Response::HTTP_FORBIDDEN);
    }

    public function test_store_rejects_duplicate_code_within_tenant(): void
    {
        $this->createComponentFixture($this->tenantId, 'BASE_SALARY');

        $response = $this
            ->withToken($this->issueToken())
            ->postJson(
                route('api.v1.hr.compensation.components.store', [], false),
                [
                    'code' => 'BASE_SALARY',
                    'name' => 'Gaji Pokok Duplikat',
                    'category' => CompensationComponent::CATEGORY_BASE_PAY,
                    'value_mode' => CompensationComponent::VALUE_MODE_FIXED_AMOUNT,
                    'periodicity' => 'MONTHLY',
                ],
            );

        $response->assertStatus(Response::HTTP_UNPROCESSABLE_ENTITY);
        $response->assertJsonValidationErrors(['code']);
    }

    public function test_store_rejects_mismatched_unit_code_for_value_mode(): void
    {
        $response = $this
            ->withToken($this->issueToken())
            ->postJson(
                route('api.v1.hr.compensation.components.store', [], false),
                [
                    'code' => 'INVALID_RATE',
                    'name' => 'Rate Tanpa Unit',
                    'category' => CompensationComponent::CATEGORY_RATE,
                    'value_mode' => CompensationComponent::VALUE_MODE_RATE_PER_UNIT,
                    'periodicity' => 'PER_UNIT',
                    // unit_code sengaja dihilangkan — RATE_PER_UNIT
                    // wajib mengisinya (CHECK constraint DB).
                ],
            );

        $response->assertStatus(Response::HTTP_UNPROCESSABLE_ENTITY);
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
            'name' => 'Compensation Component Controller Tenant',
            'subdomain' => sprintf(
                'compensation-component-%s',
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
            'name' => 'Compensation Component Operator',
            'status' => 'ACTIVE',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('users')->insert([
            'id' => $this->operatorUserId,
            'person_id' => $personId,
            'email' => sprintf(
                'compensation-component-operator-%s@educore.test',
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

    private function createComponentFixture(
        string $tenantId,
        string $code,
    ): void {
        DB::table('compensation_components')->insert([
            'id' => UuidV7::generate(),
            'tenant_id' => $tenantId,
            'code' => $code,
            'name' => 'Komponen Uji ' . $code,
            'category' => CompensationComponent::CATEGORY_BASE_PAY,
            'value_mode' => CompensationComponent::VALUE_MODE_FIXED_AMOUNT,
            'unit_code' => null,
            'periodicity' => 'MONTHLY',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
