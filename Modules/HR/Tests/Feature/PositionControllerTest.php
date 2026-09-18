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
use Symfony\Component\HttpFoundation\Response;
use Tests\Support\GrantsAuthorizationRole;
use Tests\Support\GrantsSubscriptionFeature;
use Tests\TestCase;

final class PositionControllerTest extends TestCase
{
    use GrantsAuthorizationRole;
    use GrantsSubscriptionFeature;
    use RefreshDatabase;

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

    public function test_index_lists_positions_for_current_tenant(): void
    {
        $this->createPositionFixture($this->tenantId, 'GURU_MTK');

        $otherTenantId = UuidV7::generate();
        $this->createTenantFixture($otherTenantId);
        $this->createPositionFixture($otherTenantId, 'GURU_MTK_OTHER');

        $response = $this
            ->withToken($this->issueToken())
            ->getJson(
                route('api.v1.hr.positions.index', [], false),
            );

        $response->assertOk();

        $codes = collect($response->json('data'))->pluck('code');

        $this->assertTrue($codes->contains('GURU_MTK'));
        $this->assertFalse(
            $codes->contains('GURU_MTK_OTHER'),
            'Position belonging to another tenant must never appear.',
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
                route('api.v1.hr.positions.index', [], false),
            );

        $response->assertStatus(Response::HTTP_FORBIDDEN);
    }

    public function test_store_creates_new_position(): void
    {
        $response = $this
            ->withToken($this->issueToken())
            ->postJson(
                route('api.v1.hr.positions.store', [], false),
                [
                    'code' => 'GURU_MTK',
                    'name' => 'Guru Matematika',
                ],
            );

        $response->assertCreated();

        $this->assertDatabaseHas('positions', [
            'tenant_id' => $this->tenantId,
            'code' => 'GURU_MTK',
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
                route('api.v1.hr.positions.store', [], false),
                [
                    'code' => 'GURU_MTK',
                    'name' => 'Guru Matematika',
                ],
            );

        $response->assertStatus(Response::HTTP_FORBIDDEN);
    }

    public function test_store_rejects_duplicate_code_within_tenant(): void
    {
        $this->createPositionFixture($this->tenantId, 'GURU_MTK');

        $response = $this
            ->withToken($this->issueToken())
            ->postJson(
                route('api.v1.hr.positions.store', [], false),
                [
                    'code' => 'GURU_MTK',
                    'name' => 'Guru Matematika Duplikat',
                ],
            );

        $response->assertStatus(Response::HTTP_UNPROCESSABLE_ENTITY);
        $response->assertJsonValidationErrors(['code']);
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
            'name' => 'Position Controller Tenant',
            'subdomain' => sprintf(
                'position-%s',
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
            'name' => 'Position Controller Operator',
            'status' => 'ACTIVE',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('users')->insert([
            'id' => $this->operatorUserId,
            'person_id' => $personId,
            'email' => sprintf(
                'position-operator-%s@educore.test',
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

    private function createPositionFixture(
        string $tenantId,
        string $code,
    ): void {
        DB::table('positions')->insert([
            'id' => UuidV7::generate(),
            'tenant_id' => $tenantId,
            'code' => $code,
            'name' => 'Jabatan Uji '.$code,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
