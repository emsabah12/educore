<?php

declare(strict_types=1);

namespace Modules\Core\Tests\Feature;

use Illuminate\Console\Command;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Core\Authorization\Database\Seeders\AuthorizationCatalogSeeder;
use Modules\Core\Support\Uuid\UuidV7;
use Tests\Support\GrantsAuthorizationRole;
use Tests\TestCase;

final class TenantBackfillDefaultOrganizationCommandTest extends TestCase
{
    use RefreshDatabase;
    use GrantsAuthorizationRole;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(AuthorizationCatalogSeeder::class);
    }

    public function test_command_creates_default_organizations_for_tenants_missing_one(): void
    {
        $tenantOneId = $this->createTenantFixture(
            'Kampus Satu',
        );
        $adminOneId = $this->createMembershipFixture(
            $tenantOneId,
        );
        $this->grantRole($adminOneId, 'admin');

        $tenantTwoId = $this->createTenantFixture(
            'Kampus Dua',
        );
        $adminTwoId = $this->createMembershipFixture(
            $tenantTwoId,
        );
        $this->grantRole($adminTwoId, 'admin');

        $this
            ->artisan('core:tenant-backfill-default-organization')
            ->assertExitCode(Command::SUCCESS);

        $this->assertDatabaseHas('organizations', [
            'tenant_id' => $tenantOneId,
            'name' => 'Kampus Satu',
        ]);

        $this->assertDatabaseHas('organizations', [
            'tenant_id' => $tenantTwoId,
            'name' => 'Kampus Dua',
        ]);

        $organizationOneId = DB::table('organizations')
            ->where('tenant_id', $tenantOneId)
            ->value('id');

        $this->assertDatabaseHas('organizational_assignments', [
            'tenant_id' => $tenantOneId,
            'membership_id' => $adminOneId,
            'organization_id' => $organizationOneId,
            'status' => 'ACTIVE',
        ]);
    }

    public function test_command_skips_tenant_that_already_has_an_organization(): void
    {
        $tenantId = $this->createTenantFixture(
            'Kampus Sudah Onboarded',
        );

        $existingOrganizationId = UuidV7::generate();
        DB::table('organizations')->insert([
            'id' => $existingOrganizationId,
            'tenant_id' => $tenantId,
            'name' => 'Organisasi Manual',
            'code' => null,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this
            ->artisan('core:tenant-backfill-default-organization')
            ->assertExitCode(Command::SUCCESS);

        $this->assertDatabaseCount('organizations', 1);

        $this->assertDatabaseHas('organizations', [
            'id' => $existingOrganizationId,
            'name' => 'Organisasi Manual',
        ]);
    }

    public function test_command_skips_inactive_tenant(): void
    {
        $tenantId = $this->createTenantFixture(
            'Kampus Non Aktif',
            isActive: false,
        );

        $this
            ->artisan('core:tenant-backfill-default-organization')
            ->assertExitCode(Command::SUCCESS);

        $this->assertDatabaseMissing('organizations', [
            'tenant_id' => $tenantId,
        ]);
    }

    public function test_command_dry_run_does_not_modify_database(): void
    {
        $tenantId = $this->createTenantFixture(
            'Kampus Dry Run',
        );
        $adminId = $this->createMembershipFixture(
            $tenantId,
        );
        $this->grantRole($adminId, 'admin');

        $this
            ->artisan(
                'core:tenant-backfill-default-organization',
                ['--dry-run' => true],
            )
            ->assertExitCode(Command::SUCCESS);

        $this->assertDatabaseMissing('organizations', [
            'tenant_id' => $tenantId,
        ]);

        $this->assertDatabaseMissing('organizational_assignments', [
            'membership_id' => $adminId,
        ]);
    }

    public function test_command_is_idempotent_across_repeated_runs(): void
    {
        $tenantId = $this->createTenantFixture(
            'Kampus Berulang',
        );
        $adminId = $this->createMembershipFixture(
            $tenantId,
        );
        $this->grantRole($adminId, 'admin');

        $this
            ->artisan('core:tenant-backfill-default-organization')
            ->assertExitCode(Command::SUCCESS);

        $this
            ->artisan('core:tenant-backfill-default-organization')
            ->assertExitCode(Command::SUCCESS);

        $this->assertDatabaseCount('organizations', 1);
        $this->assertDatabaseCount('organizational_assignments', 1);
    }

    public function test_command_succeeds_when_no_active_tenant_exists(): void
    {
        DB::table('tenants')->delete();

        $this
            ->artisan('core:tenant-backfill-default-organization')
            ->assertExitCode(Command::SUCCESS);

        $this->assertDatabaseCount('organizations', 0);
    }

    private function createTenantFixture(
        string $name,
        bool $isActive = true,
    ): string {
        $tenantId = UuidV7::generate();

        DB::table('tenants')->insert([
            'id' => $tenantId,
            'name' => $name,
            'subdomain' => sprintf(
                'tenant-backfill-%s',
                Str::lower(Str::random(12)),
            ),
            'is_active' => $isActive,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $tenantId;
    }

    private function createMembershipFixture(
        string $tenantId,
    ): string {
        $personId = UuidV7::generate();

        DB::table('persons')->insert([
            'id' => $personId,
            'name' => 'Tenant Backfill Fixture Person',
            'status' => 'ACTIVE',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $membershipId = UuidV7::generate();

        DB::table('memberships')->insert([
            'id' => $membershipId,
            'person_id' => $personId,
            'tenant_id' => $tenantId,
            'status' => 'ACTIVE',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $membershipId;
    }
}
