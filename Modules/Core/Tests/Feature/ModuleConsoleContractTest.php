<?php

declare(strict_types=1);

namespace Modules\Core\Tests\Feature;

use Illuminate\Support\Facades\Artisan;
use Symfony\Component\Console\Exception\CommandNotFoundException;
use Tests\TestCase;

final class ModuleConsoleContractTest extends TestCase
{
    public function test_module_list_reports_discovered_manifest_metadata_without_runtime_state(): void
    {
        $exitCode = Artisan::call('module:list');
        $output = Artisan::output();

        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString('Technical Identifier', $output);
        $this->assertStringContainsString('Academic', $output);
        $this->assertStringNotContainsString('Runtime State', $output);
        $this->assertStringNotContainsString('Inactive', $output);
    }

    public function test_module_status_reports_manifest_metadata_without_runtime_state(): void
    {
        $exitCode = Artisan::call('module:status', ['name' => 'Academic']);
        $output = Artisan::output();

        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString('Technical Identifier', $output);
        $this->assertStringContainsString('Academic', $output);
        $this->assertStringContainsString('Dependencies', $output);
        $this->assertStringContainsString('core, HR', $output);
        $this->assertStringContainsString('Providers', $output);
        $this->assertStringContainsString(
            'Modules\\Academic\\Providers\\AcademicServiceProvider',
            $output,
        );
        $this->assertStringNotContainsString('Runtime State', $output);
    }

    public function test_module_status_fails_for_unknown_module(): void
    {
        $exitCode = Artisan::call('module:status', ['name' => 'Unknown']);
        $output = Artisan::output();

        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString(
            'Module "Unknown" is not registered or cannot be discovered.',
            $output,
        );
    }

    public function test_legacy_module_mutation_commands_are_not_registered(): void
    {
        foreach (['module:enable', 'module:disable'] as $command) {
            try {
                Artisan::call($command, ['name' => 'Academic']);

                $this->fail(sprintf(
                    'Legacy mutation command [%s] must not be registered.',
                    $command,
                ));
            } catch (CommandNotFoundException) {
                $this->addToAssertionCount(1);
            }
        }
    }
}
