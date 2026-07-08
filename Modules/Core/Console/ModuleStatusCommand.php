<?php

declare(strict_types=1);

namespace Modules\Core\Console;

use Illuminate\Console\Command;
use Modules\Core\Services\ModuleRepository;
use Modules\Core\Services\ModuleStateRepository;
use Throwable;

class ModuleStatusCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'module:status {name : The name of the module}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Display the detail specification and current status of a specific module';

    /**
     * Execute the console command.
     */
    public function handle(
        ModuleRepository $moduleRepository,
        ModuleStateRepository $stateRepository
    ): int {
        $moduleName = trim($this->argument('name'));

        try {
            // 1. Fail Fast: Cek ketersediaan modul menggunakan method yang ada di Repository (exists atau has)
            $moduleExists = false;
            if (method_exists($moduleRepository, 'exists')) {
                $moduleExists = $moduleRepository->exists($moduleName);
            } elseif (method_exists($moduleRepository, 'has')) {
                $moduleExists = $moduleRepository->has($moduleName);
            } else {
                // Pola fallback jika tidak ada method pembantu: langsung cari objeknya
                $moduleExists = $moduleRepository->find($moduleName) !== null;
            }

            if (!$moduleExists) {
                $this->components->error(sprintf('Module "%s" is not registered or cannot be discovered.', $moduleName));
                return self::FAILURE;
            }

            // 2. Ambil data spesifikasi modul secara utuh
            $module = $moduleRepository->find($moduleName);
            $isEnabled = $stateRepository->isEnabled($moduleName);

            // Defensif check terhadap gaya penamaan method di Entity (getter vs fluent)
            $name = method_exists($module, 'getName') ? $module->getName() : $module->name;
            $displayName = method_exists($module, 'getDisplayName') ? $module->getDisplayName() : (method_exists($module, 'displayName') ? $module->displayName : $name);
            $version = method_exists($module, 'getVersion') ? $module->getVersion() : (method_exists($module, 'version') ? $module->version : '0.0.0');
            $description = method_exists($module, 'getDescription') ? $module->getDescription() : (method_exists($module, 'description') ? $module->description : '-');

            $this->components->info(sprintf('Module Detail Profile: %s', $moduleName));

            // 3. Sajikan informasi mendalam dalam skema dua kolom yang rapi
            $this->components->twoColumnDetail('Technical Identifier', $name);
            $this->components->twoColumnDetail('Display Name', $displayName ?? $name);
            $this->components->twoColumnDetail('Current Version', $version ?? '0.0.0');
            $this->components->twoColumnDetail('Description', $description ?? '-');
            
            $statusLabel = $isEnabled 
                ? '<fg=green;options=bold>ACTIVE (Running)</>' 
                : '<fg=red;options=bold>INACTIVE (Suspended)</>';
                
            $this->components->twoColumnDetail('Runtime State', $statusLabel);

            return self::SUCCESS;

        } catch (Throwable $e) {
            $this->components->error(sprintf('An error occurred while fetching status for module: %s', $moduleName));
            $this->line(sprintf('<fg=red>Exception: %s</>', $e->getMessage()));
            $this->line(sprintf('File: %s:%d', $e->getFile(), $e->getLine()));
            return self::FAILURE;
        }
    }
}