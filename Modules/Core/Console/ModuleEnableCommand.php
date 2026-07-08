<?php

declare(strict_types=1);

namespace Modules\Core\Console;

use Illuminate\Console\Command;
use Modules\Core\Services\ModuleManager;
use Throwable;

class ModuleEnableCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'module:enable {name : The name of the module to enable}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Enable and activate a specific platform module';

    /**
     * Execute the console command.
     */
    public function handle(ModuleManager $moduleManager): int
    {
        $moduleName = trim($this->argument('name'));

        $this->components->info(sprintf('EduCore Operations — Activating Module: %s', $moduleName));

        try {
            // Mendelegasikan orkestrasi aturan bisnis mutasi ke Command Service
            $moduleManager->enable($moduleName);

            $this->components->info(sprintf('Success: Module "%s" has been enabled and loaded into runtime successfully.', $moduleName));
            return self::SUCCESS;

        } catch (Throwable $e) {
            $this->components->error(sprintf('Failed to enable module: %s', $moduleName));
            $this->line(sprintf('<fg=red>Reason: %s</>', $e->getMessage()));
            return self::FAILURE;
        }
    }
}