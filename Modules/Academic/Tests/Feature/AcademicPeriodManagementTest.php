<?php

declare(strict_types=1);

namespace Modules\Academic\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Academic\Database\Seeders\AcademicAuthorizationCatalogSeeder;
use Modules\Auth\Token\Contracts\TokenManagerInterface;
use Modules\Core\Support\Uuid\UuidV7;
use Tests\Support\GrantsAuthorizationRole;
use Tests\TestCase;

final class AcademicPeriodManagementTest extends TestCase
{
    use RefreshDatabase;
    use GrantsAuthorizationRole;

    private string $tenantId;
    private string $operatorPersonId;
    private string $operatorUserId;
    private string $operatorMembershipId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(AcademicAuthorizationCatalogSeeder::class);

        $this->tenantId = UuidV7::generate();
        $this->operatorPersonId = UuidV7::generate();
        $this->operatorUserId = UuidV7::generate();
        $this->operatorMembershipId = UuidV7::generate();

        $this->createAuthenticatedTenantFixture();
    }

    public function test_store_year_creates_academic_year_when_operator_has_write_permission(): void
    {
        $this->grantRole(
            $this->operatorMembershipId,
            AcademicAuthorizationCatalogSeeder::REGISTRAR_ROLE,
        );

        $response = $this
            ->withToken($this->issueToken())
            ->postJson(route('api.v1.academic.years.store', [], false), [
                'name' => '2026/2027',
                'start_date' => '2026-07-01',
                'end_date' => '2027-06-30',
                'is_active' => true,
            ]);

        $response
            ->assertCreated()
            ->assertJsonPath('status', 'success')
            ->assertJsonPath('data.name', '2026/2027');

        $this->assertDatabaseHas('academic_years', [
            'tenant_id' => $this->tenantId,
            'name' => '2026/2027',
        ]);
    }

    public function test_store_year_is_forbidden_without_years_write_permission(): void
    {
        $response = $this
            ->withToken($this->issueToken())
            ->postJson(route('api.v1.academic.years.store', [], false), [
                'name' => '2026/2027',
                'start_date' => '2026-07-01',
                'end_date' => '2027-06-30',
            ]);

        $response->assertForbidden();

        $this->assertDatabaseMissing('academic_years', [
            'tenant_id' => $this->tenantId,
            'name' => '2026/2027',
        ]);
    }

    public function test_store_year_is_forbidden_with_read_only_permission(): void
    {
        $this->grantRole(
            $this->operatorMembershipId,
            AcademicAuthorizationCatalogSeeder::TEACHER_ROLE,
        );

        $response = $this
            ->withToken($this->issueToken())
            ->postJson(route('api.v1.academic.years.store', [], false), [
                'name' => '2026/2027',
                'start_date' => '2026-07-01',
                'end_date' => '2027-06-30',
            ]);

        $response->assertForbidden();
    }

    public function test_index_years_is_forbidden_without_years_read_permission(): void
    {
        $response = $this
            ->withToken($this->issueToken())
            ->getJson(route('api.v1.academic.years.index', [], false));

        $response->assertForbidden();
    }

    public function test_index_years_lists_years_when_operator_has_read_permission(): void
    {
        $this->grantRole(
            $this->operatorMembershipId,
            AcademicAuthorizationCatalogSeeder::TEACHER_ROLE,
        );

        DB::table('academic_years')->insert([
            'id' => UuidV7::generate(),
            'tenant_id' => $this->tenantId,
            'name' => '2025/2026',
            'start_date' => '2025-07-01',
            'end_date' => '2026-06-30',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $response = $this
            ->withToken($this->issueToken())
            ->getJson(route('api.v1.academic.years.index', [], false));

        $response
            ->assertOk()
            ->assertJsonPath('status', 'success')
            ->assertJsonPath('data.0.name', '2025/2026');
    }

    public function test_store_semester_creates_semester_when_operator_has_write_permission(): void
    {
        $this->grantRole(
            $this->operatorMembershipId,
            AcademicAuthorizationCatalogSeeder::REGISTRAR_ROLE,
        );

        $yearId = $this->createAcademicYear('2026/2027');

        $response = $this
            ->withToken($this->issueToken())
            ->postJson(
                route(
                    'api.v1.academic.semesters.store',
                    ['yearId' => $yearId],
                    false,
                ),
                [
                    'name' => 'Ganjil 2026/2027',
                    'type' => 'GANJIL',
                    'is_active' => true,
                ],
            );

        $response
            ->assertCreated()
            ->assertJsonPath('status', 'success')
            ->assertJsonPath('data.name', 'Ganjil 2026/2027');

        $this->assertDatabaseHas('academic_semesters', [
            'tenant_id' => $this->tenantId,
            'academic_year_id' => $yearId,
            'name' => 'Ganjil 2026/2027',
        ]);
    }

    public function test_store_semester_is_forbidden_without_years_write_permission(): void
    {
        $yearId = $this->createAcademicYear('2026/2027');

        $response = $this
            ->withToken($this->issueToken())
            ->postJson(
                route(
                    'api.v1.academic.semesters.store',
                    ['yearId' => $yearId],
                    false,
                ),
                [
                    'name' => 'Ganjil 2026/2027',
                    'type' => 'GANJIL',
                ],
            );

        $response->assertForbidden();

        $this->assertDatabaseMissing('academic_semesters', [
            'tenant_id' => $this->tenantId,
            'academic_year_id' => $yearId,
        ]);
    }

    private function createAcademicYear(string $name): string
    {
        $yearId = UuidV7::generate();

        DB::table('academic_years')->insert([
            'id' => $yearId,
            'tenant_id' => $this->tenantId,
            'name' => $name,
            'start_date' => '2026-07-01',
            'end_date' => '2027-06-30',
            'is_active' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $yearId;
    }

    private function createAuthenticatedTenantFixture(): void
    {
        DB::table('tenants')->insert([
            'id' => $this->tenantId,
            'name' => 'Canonical Period Tenant',
            'subdomain' => sprintf(
                'period-%s',
                Str::lower(Str::random(12)),
            ),
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('persons')->insert([
            'id' => $this->operatorPersonId,
            'name' => 'Period Operator',
            'status' => 'ACTIVE',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('users')->insert([
            'id' => $this->operatorUserId,
            'person_id' => $this->operatorPersonId,
            'email' => sprintf(
                'period-operator-%s@educore.test',
                Str::lower(Str::random(10)),
            ),
            'password' => 'not-used-by-token-test',
            'status' => 'ACTIVE',
            'is_superadmin' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('memberships')->insert([
            'id' => $this->operatorMembershipId,
            'person_id' => $this->operatorPersonId,
            'tenant_id' => $this->tenantId,
            'status' => 'ACTIVE',
            'created_at' => now(),
            'updated_at' => now(),
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
}
