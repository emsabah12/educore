<?php

declare(strict_types=1);

namespace Modules\Core\Console;

use Illuminate\Console\Command;
use Modules\Core\Services\ModuleRepository;

final class TestModuleLoaderCommand extends Command
{
    /**
     * The name and signature of the console command.
     */
    protected $signature = 'kernel:test-loader';

    /**
     * The console command description.
     */
    protected $description = 'Verify and audit platform kernel bootstrapping integrity from the registry';

    /**
     * Execute the console command.
     */
    public function handle(ModuleRepository $moduleRepository): int
    {
        $this->info('--- Platform Kernel Registry Audit ---');

        $totalModules = $moduleRepository->count();
        $this->comment(sprintf('Total Discovered Modules: %d', $totalModules));

        if ($totalModules === 0) {
            $this->warn('Warning: Platform kernel registry is empty. Check module discovery path.');
            return self::FAILURE;
        }

        // Membaca data secara aman via Query Model tanpa menyentuh filesystem lagi
        foreach ($moduleRepository->all() as $module) {
            $this->line(sprintf(' - <fg=cyan>[%s]</> Version: %s', $module->name, $module->version));
        }

        return self::SUCCESS;
    }
}