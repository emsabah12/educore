<?php

declare(strict_types=1);

namespace Modules\Core\Tests\Feature;

use Illuminate\Console\Command;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class TenantProvisionCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_command_provisions_tenant_through_canonical_repository(): void
    {
        $this
            ->artisan(
                'core:tenant-provision',
                [
                    '--name' => '  Sekolah Command  ',
                    '--subdomain' => '  SEKOLAH-COMMAND  ',
                    '--domain' => '  sekolah-command.educore.test  ',
                ],
            )
            ->assertExitCode(
                Command::SUCCESS,
            );

        $this->assertDatabaseHas('tenants', [
            'name' => 'Sekolah Command',
            'subdomain' => 'sekolah-command',
            'domain' => 'sekolah-command.educore.test',
            'is_active' => true,
        ]);

        $settings = DB::table('tenants')
            ->where(
                'subdomain',
                'sekolah-command',
            )
            ->value('settings');

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
    }

    public function test_command_rejects_invalid_subdomain(): void
    {
        $this
            ->artisan(
                'core:tenant-provision',
                [
                    '--name' => 'Sekolah Invalid',
                    '--subdomain' => 'tenant_invalid',
                ],
            )
            ->assertExitCode(
                Command::FAILURE,
            );

        $this->assertDatabaseMissing('tenants', [
            'name' => 'Sekolah Invalid',
        ]);
    }
}
