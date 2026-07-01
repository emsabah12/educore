<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Modules\Core\Registry\ModuleRegistry;
use Modules\Core\Services\ModuleStateRepository;

final class ModuleListCommand extends Command
{
    protected $signature = 'module:list';

    protected $description = 'List all discovered modules';

    public function handle(ModuleRegistry $registry, ModuleStateRepository $stateRepository): int
    {
        $rows = [];

        foreach ($registry->all() as $module) {
            $rows[] = [
                'ID' => $module->id(),
                'Name' => $module->name(),
                'Version' => $module->version(),
                'Status' => $stateRepository->isEnabled($module->id())
            ? 'Enabled'
            : 'Disabled',
            ];
        }

        if ($rows === []) {
            $this->warn('No modules registered.');

            return self::SUCCESS;
        }

        $this->table(
            ['ID', 'Name', 'Version', 'Status'],
            $rows
        );

        $this->info(sprintf(
            'Total Modules: %d',
            $registry->count()
        ));

        return self::SUCCESS;
    }
}