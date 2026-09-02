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

final class AcademicSubjectManagementTest extends TestCase
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

    public function test_store_creates_academic_subject_when_operator_has_write_permission(): void
    {
        $this->grantRole(
            $this->operatorMembershipId,
            AcademicAuthorizationCatalogSeeder::REGISTRAR_ROLE,
        );

        $response = $this
            ->withToken($this->issueToken())
            ->postJson(route('api.v1.academic.subjects.store', [], false), [
                'name' => 'Matematika Wajib',
                'code' => 'MTK-11',
                'category' => 'NASIONAL',
            ]);

        $response
            ->assertCreated()
            ->assertJsonPath('status', 'success')
            ->assertJsonPath('data.name', 'Matematika Wajib')
            ->assertJsonPath('data.tenant_id', $this->tenantId);

        $this->assertDatabaseHas('academic_subjects', [
            'tenant_id' => $this->tenantId,
            'name' => 'Matematika Wajib',
            'code' => 'MTK-11',
        ]);
    }

    public function test_store_is_forbidden_without_subjects_write_permission(): void
    {
        $response = $this
            ->withToken($this->issueToken())
            ->postJson(route('api.v1.academic.subjects.store', [], false), [
                'name' => 'Matematika Wajib',
                'code' => 'MTK-11',
                'category' => 'NASIONAL',
            ]);

        $response->assertForbidden();

        $this->assertDatabaseMissing('academic_subjects', [
            'tenant_id' => $this->tenantId,
            'name' => 'Matematika Wajib',
        ]);
    }

    public function test_store_is_forbidden_with_read_only_permission(): void
    {
        $this->grantRole(
            $this->operatorMembershipId,
            AcademicAuthorizationCatalogSeeder::TEACHER_ROLE,
        );

        $response = $this
            ->withToken($this->issueToken())
            ->postJson(route('api.v1.academic.subjects.store', [], false), [
                'name' => 'Matematika Wajib',
                'code' => 'MTK-11',
                'category' => 'NASIONAL',
            ]);

        $response->assertForbidden();
    }

    public function test_index_lists_subjects_when_operator_has_read_permission(): void
    {
        $this->grantRole(
            $this->operatorMembershipId,
            AcademicAuthorizationCatalogSeeder::TEACHER_ROLE,
        );

        DB::table('academic_subjects')->insert([
            'id' => UuidV7::generate(),
            'tenant_id' => $this->tenantId,
            'name' => 'Bahasa Arab',
            'code' => 'AR-01',
            'category' => 'PESANTREN',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $response = $this
            ->withToken($this->issueToken())
            ->getJson(route('api.v1.academic.subjects.index', [], false));

        $response
            ->assertOk()
            ->assertJsonPath('status', 'success')
            ->assertJsonPath('data.0.name', 'Bahasa Arab');
    }

    public function test_index_is_forbidden_without_subjects_read_permission(): void
    {
        $response = $this
            ->withToken($this->issueToken())
            ->getJson(route('api.v1.academic.subjects.index', [], false));

        $response->assertForbidden();
    }

    public function test_store_validates_category_enum(): void
    {
        $this->grantRole(
            $this->operatorMembershipId,
            AcademicAuthorizationCatalogSeeder::REGISTRAR_ROLE,
        );

        $response = $this
            ->withToken($this->issueToken())
            ->postJson(route('api.v1.academic.subjects.store', [], false), [
                'name' => 'Matematika Wajib',
                'code' => 'MTK-11',
                'category' => 'TIDAK_VALID',
            ]);

        $response
            ->assertStatus(422)
            ->assertJsonValidationErrors(['category']);
    }

    private function createAuthenticatedTenantFixture(): void
    {
        DB::table('tenants')->insert([
            'id' => $this->tenantId,
            'name' => 'Canonical Subject Tenant',
            'subdomain' => sprintf(
                'subject-%s',
                Str::lower(Str::random(12)),
            ),
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('persons')->insert([
            'id' => $this->operatorPersonId,
            'name' => 'Subject Operator',
            'status' => 'ACTIVE',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('users')->insert([
            'id' => $this->operatorUserId,
            'person_id' => $this->operatorPersonId,
            'email' => sprintf(
                'subject-operator-%s@educore.test',
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
