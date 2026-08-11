<?php

declare(strict_types=1);

namespace Modules\Core\Tests\Feature;

use Illuminate\Console\Command;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Modules\Core\Authorization\Database\Seeders\AuthorizationCatalogSeeder;
use Modules\Core\Identity\Models\User;
use Modules\Core\Support\Uuid\UuidV7;
use Tests\TestCase;

final class TenantProvisionCommandTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(
            AuthorizationCatalogSeeder::class,
        );
    }

    public function test_command_provisions_tenant_membership_and_admin_role(): void
    {
        $admin = User::factory()->create();

        $this
            ->artisan(
                'core:tenant-provision',
                [
                    '--name' => '  Sekolah Command  ',
                    '--subdomain' => '  SEKOLAH-COMMAND  ',
                    '--domain' => '  sekolah-command.educore.test  ',
                    '--admin-user-id' => (string) $admin->id,
                ],
            )
            ->assertExitCode(
                Command::SUCCESS,
            );

        $tenant = DB::table('tenants')
            ->where(
                'subdomain',
                'sekolah-command',
            )
            ->first();

        $this->assertNotNull($tenant);

        $this->assertSame(
            'Sekolah Command',
            $tenant->name,
        );

        $this->assertSame(
            'sekolah-command.educore.test',
            $tenant->domain,
        );

        $this->assertTrue(
            (bool) $tenant->is_active,
        );

        $this->assertTrue(
            UuidV7::validate((string) $tenant->id),
        );

        $settings = $tenant->settings;

        $this->assertIsString($settings);

        /** @var array<string, mixed> $decodedSettings */
        $decodedSettings = json_decode(
            $settings,
            true,
            512,
            JSON_THROW_ON_ERROR,
        );

        $this->assertSame(
            'Artisan CLI',
            $decodedSettings['provisioned_via'],
        );

        $this->assertArrayHasKey(
            'created_at',
            $decodedSettings,
        );

        $membership = DB::table('memberships')
            ->where('person_id', (string) $admin->person_id)
            ->where('tenant_id', (string) $tenant->id)
            ->first();

        $this->assertNotNull($membership);
        $this->assertSame('ACTIVE', $membership->status);

        $adminRoleId = DB::table('roles')
            ->where('name', 'admin')
            ->value('id');

        $this->assertDatabaseHas('membership_roles', [
            'membership_id' => $membership->id,
            'role_id' => $adminRoleId,
        ]);
    }

    public function test_command_rejects_invalid_subdomain_without_partial_tenant(): void
    {
        $admin = User::factory()->create();

        $this
            ->artisan(
                'core:tenant-provision',
                [
                    '--name' => 'Sekolah Invalid',
                    '--subdomain' => 'tenant_invalid',
                    '--admin-user-id' => (string) $admin->id,
                ],
            )
            ->assertExitCode(
                Command::FAILURE,
            );

        $this->assertDatabaseMissing('tenants', [
            'name' => 'Sekolah Invalid',
        ]);
    }

    public function test_command_rejects_unknown_admin_user_without_partial_tenant(): void
    {
        $this
            ->artisan(
                'core:tenant-provision',
                [
                    '--name' => 'Sekolah Unknown Admin',
                    '--subdomain' => 'unknown-admin',
                    '--admin-user-id' => UuidV7::generate(),
                ],
            )
            ->assertExitCode(
                Command::FAILURE,
            );

        $this->assertDatabaseMissing('tenants', [
            'subdomain' => 'unknown-admin',
        ]);
    }
}
