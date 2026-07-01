<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Modules\Core\Exceptions\ModuleNotFoundException;
use Modules\Core\Registry\ModuleRegistry;
use Modules\Core\Services\ModuleStateRepository;

final class ModuleStatusCommand extends Command
{
    protected $signature = 'module:status {id : Module ID}';

    protected $description = 'Show detailed information about a module';

    public function handle(ModuleRegistry $registry, ModuleStateRepository $stateRepository): int
    {
        $id = strtolower((string) $this->argument('id'));

        try {
            $module = $registry->get($id);
        } catch (ModuleNotFoundException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->info('Module Status');
        $this->newLine();

        $this->line(sprintf('ID           : %s', $module->id()));
        $this->line(sprintf('Name         : %s', $module->name()));
        $this->line(sprintf('Version      : %s', $module->version()));
        $this->line(sprintf('Description  : %s', $module->description()));
        $this->line(sprintf('Schema       : %d', $module->schema()));
        $status = $stateRepository->isEnabled($module->id())
    ? 'Enabled'
    : 'Disabled';

$this->line(sprintf('Status       : %s', $status));

        $this->newLine();

        $this->info('Providers');

        foreach ($module->providers() as $provider) {
            $this->line("  • {$provider}");
        }

        $this->newLine();

        $this->info('Dependencies');

        if ($module->dependencies() === []) {
            $this->line('  • -');
        } else {
            foreach ($module->dependencies() as $dependency) {
                $this->line("  • {$dependency}");
            }
        }

        $this->newLine();

        $this->info('Metadata');

        foreach ($module->metadata() as $key => $value) {
            $this->line(sprintf('  • %-10s : %s', $key, $value));
        }

        return self::SUCCESS;
    }
}