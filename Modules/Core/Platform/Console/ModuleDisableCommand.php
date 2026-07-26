<?php

declare(strict_types=1);

namespace Modules\Core\Platform\Console;

use Illuminate\Console\Command;
use Modules\Core\Platform\Module\Services\ModuleManager;
use Throwable;

class ModuleDisableCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'module:disable {name : The name of the module to disable}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Disable and suspend a specific platform module';

    /**
     * Execute the console command.
     */
    public function handle(ModuleManager $moduleManager): int
    {
        $moduleName = trim($this->argument('name'));

        $this->components->info(sprintf('EduCore Operations — Deactivating Module: %s', $moduleName));

        try {
            // Mendelegasikan orkestrasi aturan bisnis mutasi ke Command Service
            $moduleManager->disable($moduleName);

            $this->components->warn(sprintf('Success: Module "%s" has been disabled and suspended from runtime.', $moduleName));
            return self::SUCCESS;
        } catch (Throwable $e) {
            $this->components->error(sprintf('Failed to disable module: %s', $moduleName));
            $this->line(sprintf('<fg=red>Reason: %s</>', $e->getMessage()));
            return self::FAILURE;
        }
    }
}
