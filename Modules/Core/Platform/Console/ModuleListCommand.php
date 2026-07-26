<?php

declare(strict_types=1);

namespace Modules\Core\Platform\Console;

use Illuminate\Console\Command;
use Modules\Core\Services\ModuleRepository;
use Modules\Core\Services\ModuleStateRepository;
use Throwable;

class ModuleListCommand extends Command
{
    protected $signature = 'module:list';
    protected $description = 'Display a list of all discovered modules and their runtime states';

    public function handle(
        ModuleRepository $moduleRepository,
        ModuleStateRepository $stateRepository
    ): int {
        $this->components->info('EduCore Platform Kernel — Module Discovery System');

        try {
            $modules = $moduleRepository->all();

            if (empty($modules)) {
                $this->components->warn('No modules discovered in the system.');
                return self::SUCCESS;
            }

            $headers = ['Module Name', 'Version', 'Description', 'Runtime State'];
            $rows = [];

            foreach ($modules as $module) {
                // Defensif check terhadap gaya penamaan method di Entity
                $name = method_exists($module, 'getName') ? $module->getName() : $module->name;
                $version = method_exists($module, 'getVersion') ? $module->getVersion() : $module->version;
                $description = method_exists($module, 'getDescription') ? $module->getDescription() : $module->description;

                $isEnabled = $stateRepository->isEnabled($name);

                $rows[] = [
                    $name,
                    $version ?? '0.0.0',
                    $description ?? '-',
                    $isEnabled
                        ? '<fg=green;options=bold>Active</>'
                        : '<fg=red;options=bold>Inactive</>'
                ];
            }

            $this->table($headers, $rows);
            $this->newLine();
            $this->components->twoColumnDetail('Total Discovered Modules', (string) count($modules));

            return self::SUCCESS;
        } catch (Throwable $e) {
            $this->components->error('An error occurred while loading modules.');
            $this->line(sprintf('<fg=red>Exception: %s</>', $e->getMessage()));
            return self::FAILURE;
        }
    }
}
