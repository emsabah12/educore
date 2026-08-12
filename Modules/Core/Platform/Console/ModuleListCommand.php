<?php

declare(strict_types=1);

namespace Modules\Core\Platform\Console;

use Illuminate\Console\Command;
use Modules\Core\Services\ModuleRepository;
use Throwable;

class ModuleListCommand extends Command
{
    protected $signature = 'module:list';

    protected $description = 'Display all discovered modules and their manifest metadata';

    public function handle(ModuleRepository $moduleRepository): int
    {
        $this->components->info('EduCore Platform Kernel — Discovered Modules');

        try {
            $modules = $moduleRepository->all();

            if ($modules === []) {
                $this->components->warn('No modules discovered in the system.');

                return self::SUCCESS;
            }

            $rows = [];

            foreach ($modules as $module) {
                $rows[] = [
                    $module->name,
                    $module->displayName,
                    $module->version,
                    $module->description,
                    $module->dependencies === []
                        ? '-'
                        : implode(', ', $module->dependencies),
                ];
            }

            $this->table(
                [
                    'Technical Identifier',
                    'Display Name',
                    'Version',
                    'Description',
                    'Dependencies',
                ],
                $rows,
            );

            $this->newLine();
            $this->components->twoColumnDetail(
                'Total Discovered Modules',
                (string) count($modules),
            );

            return self::SUCCESS;
        } catch (Throwable $exception) {
            report($exception);

            $this->components->error(
                'Unable to list discovered modules. Check the application logs for details.',
            );

            return self::FAILURE;
        }
    }
}
