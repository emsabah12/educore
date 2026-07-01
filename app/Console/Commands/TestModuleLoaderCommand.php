<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Modules\Core\Registry\ModuleRegistry;

final class TestModuleLoaderCommand extends Command
{
    protected $signature = 'kernel:test-loader';

    protected $description = 'Show loaded modules from Kernel Registry';

    public function handle(ModuleRegistry $registry): int
    {
        $this->info('Module Count : '.$registry->count());

        foreach ($registry->all() as $module) {
            $this->line(sprintf(
                '%s (%s)',
                $module->name(),
                $module->version(),
            ));
        }

        return self::SUCCESS;
    }
}