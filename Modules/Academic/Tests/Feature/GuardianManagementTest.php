<?php

declare(strict_types=1);

namespace Modules\Academic\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Mockery\MockInterface;
use Modules\Academic\Contracts\GuardianRepositoryInterface;
use Modules\Academic\Database\Seeders\AcademicAuthorizationCatalogSeeder;
use Modules\Auth\Token\Contracts\TokenManagerInterface;
use Modules\Core\Support\Uuid\UuidV7;
use RuntimeException;
use Tests\Support\GrantsAuthorizationRole;
use Tests\TestCase;

final class GuardianManagementTest extends TestCase
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

    public function test_store_atomically_provisions_person_membership_phone_and_guardian_without_user_account(): void
    {
        $beforeUsers = DB::table('users')->count();
        $beforePersons = DB::table('persons')->count();
        $beforeMemberships = DB::table('memberships')->count();
        $beforeContacts = DB::table('person_contacts')->count();

        $response = $this
            ->withToken($this->issueToken())
            ->postJson(route('api.v1.academic.guardians.store', [], false), [
                'nama' => '  H. Ahmad Sulaiman  ',
                'no_hp' => '0812 345-6789',
            ]);

        $response
            ->assertCreated()
            ->assertJsonPath('status', 'success')
            ->assertJsonPath('data.nama', 'H. Ahmad Sulaiman')
            ->assertJsonPath('data.no_hp', '08123456789')
            ->assertJsonPath('data.membership_status', 'ACTIVE');

        $guardianId = (string) $response->json('data.guardian_id');
        $membershipId = (string) $response->json('data.membership_id');
        $personId = (string) $response->json('data.person_id');

        $this->assertTrue(UuidV7::validate($guardianId));
        $this->assertTrue(UuidV7::validate($membershipId));
        $this->assertTrue(UuidV7::validate($personId));

        $this->assertSame(
            $beforeUsers,
            DB::table('users')->count(),
            'Guardian provisioning must not create a digital User account.',
        );
        $this->assertSame(
            $beforePersons + 1,
            DB::table('persons')->count(),
        );
        $this->assertSame(
            $beforeMemberships + 1,
            DB::table('memberships')->count(),
        );
        $this->assertSame(
            $beforeContacts + 1,
            DB::table('person_contacts')->count(),
        );

        $this->assertDatabaseHas('persons', [
            'id' => $personId,
            'name' => 'H. Ahmad Sulaiman',
            'status' => 'ACTIVE',
        ]);
        $this->assertDatabaseHas('memberships', [
            'id' => $membershipId,
            'person_id' => $personId,
            'tenant_id' => $this->tenantId,
            'status' => 'ACTIVE',
        ]);
        $this->assertDatabaseHas('person_contacts', [
            'person_id' => $personId,
            'type' => 'phone',
            'value' => '08123456789',
            'normalized_value' => '08123456789',
            'is_primary' => true,
        ]);
        $this->assertDatabaseHas('guardians', [
            'id' => $guardianId,
            'tenant_id' => $this->tenantId,
            'membership_id' => $membershipId,
        ]);

        $this->assertFalse(Schema::hasColumn('guardians', 'person_id'));
        $this->assertFalse(Schema::hasColumn('guardians', 'no_hp'));
        $this->assertFalse(Schema::hasColumn('guardians', 'alamat_domisili'));
    }

    public function test_guardian_repository_failure_rolls_back_person_membership_and_phone_contact(): void
    {
        $beforePersons = DB::table('persons')->count();
        $beforeMemberships = DB::table('memberships')->count();
        $beforeContacts = DB::table('person_contacts')->count();
        $beforeGuardians = DB::table('guardians')->count();

        $this->mock(
            GuardianRepositoryInterface::class,
            static function (MockInterface $mock): void {
                $mock->shouldReceive('createProfileForTenant')
                    ->once()
                    ->andThrow(new RuntimeException('forced guardian persistence failure'));
            },
        );

        $this
            ->withToken($this->issueToken())
            ->postJson(route('api.v1.academic.guardians.store', [], false), [
                'nama' => 'Rollback Guardian',
                'no_hp' => '+62 812-000-111',
            ])
            ->assertInternalServerError();

        $this->assertSame($beforePersons, DB::table('persons')->count());
        $this->assertSame($beforeMemberships, DB::table('memberships')->count());
        $this->assertSame($beforeContacts, DB::table('person_contacts')->count());
        $this->assertSame($beforeGuardians, DB::table('guardians')->count());
    }

    public function test_index_reads_name_and_primary_phone_from_person_without_duplicate_rows_or_user_identity(): void
    {
        $created = $this
            ->withToken($this->issueToken())
            ->postJson(route('api.v1.academic.guardians.store', [], false), [
                'nama' => 'Canonical Guardian Name',
                'no_hp' => '0812-111-222',
            ])
            ->assertCreated();

        $personId = (string) $created->json('data.person_id');

        DB::table('persons')
            ->where('id', $personId)
            ->update([
                'name' => 'Updated Guardian Person Name',
                'updated_at' => now(),
            ]);

        DB::table('person_contacts')->insert([
            'id' => UuidV7::generate(),
            'person_id' => $personId,
            'type' => 'phone',
            'value' => '0899999999',
            'normalized_value' => '0899999999',
            'label' => 'secondary',
            'is_primary' => false,
            'verified_at' => null,
            'created_at' => now()->addSecond(),
            'updated_at' => now()->addSecond(),
        ]);

        $response = $this
            ->withToken($this->issueToken())
            ->getJson(route('api.v1.academic.guardians.index', [], false));

        $response
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.nama', 'Updated Guardian Person Name')
            ->assertJsonPath('data.0.person_id', $personId)
            ->assertJsonPath('data.0.no_hp', '0812111222')
            ->assertJsonPath('data.0.membership_status', 'ACTIVE');

        /** @var array<string, mixed> $guardian */
        $guardian = $response->json('data.0');

        $this->assertArrayNotHasKey('user_id', $guardian);
        $this->assertArrayNotHasKey('email', $guardian);
    }

    public function test_store_allows_guardian_without_phone_contact(): void
    {
        $beforeContacts = DB::table('person_contacts')->count();

        $response = $this
            ->withToken($this->issueToken())
            ->postJson(route('api.v1.academic.guardians.store', [], false), [
                'nama' => 'Guardian Without Phone',
            ]);

        $response
            ->assertCreated()
            ->assertJsonPath('data.no_hp', null);

        $this->assertSame(
            $beforeContacts,
            DB::table('person_contacts')->count(),
        );
    }

    public function test_store_rejects_legacy_email_and_unstructured_address_contracts(): void
    {
        $this
            ->withToken($this->issueToken())
            ->postJson(route('api.v1.academic.guardians.store', [], false), [
                'nama' => 'Legacy Guardian Contract',
                'email' => 'guardian@example.test',
                'alamat_domisili' => 'Jakarta',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors([
                'email',
                'alamat_domisili',
            ]);
    }

    public function test_store_is_forbidden_when_registrar_role_is_revoked(): void
    {
        DB::table('membership_roles')
            ->where('membership_id', $this->operatorMembershipId)
            ->delete();

        $this
            ->withToken($this->issueToken())
            ->postJson(route('api.v1.academic.guardians.store', [], false), [
                'nama' => 'Unauthorized Guardian',
            ])
            ->assertForbidden();
    }

    public function test_index_is_forbidden_when_registrar_role_is_revoked(): void
    {
        DB::table('membership_roles')
            ->where('membership_id', $this->operatorMembershipId)
            ->delete();

        $this
            ->withToken($this->issueToken())
            ->getJson(route('api.v1.academic.guardians.index', [], false))
            ->assertForbidden();
    }

    private function createAuthenticatedTenantFixture(): void
    {
        DB::table('tenants')->insert([
            'id' => $this->tenantId,
            'name' => 'Canonical Guardian Tenant',
            'subdomain' => sprintf(
                'guardian-%s',
                Str::lower(Str::random(12)),
            ),
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('persons')->insert([
            'id' => $this->operatorPersonId,
            'name' => 'Guardian Operator',
            'status' => 'ACTIVE',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('users')->insert([
            'id' => $this->operatorUserId,
            'person_id' => $this->operatorPersonId,
            'email' => sprintf(
                'guardian-operator-%s@educore.test',
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

        $this->grantRole(
            $this->operatorMembershipId,
            AcademicAuthorizationCatalogSeeder::REGISTRAR_ROLE,
        );
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
