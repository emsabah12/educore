<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Modules\Core\Exceptions\ModuleNotFoundException;
use Modules\Core\Services\ModuleManager;

final class ModuleEnableCommand extends Command
{
    protected $signature = 'module:enable {name : Module Name}';

    protected $description = 'Enable a module';

    public function handle(ModuleManager $manager): int
    {
        $name = strtolower((string) $this->argument('name'));

        try {
            $changed = $manager->enable($name);
        } catch (ModuleNotFoundException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        if ($changed) {
            $this->info(sprintf(
                'Module [%s] has been enabled.',
                $name
            ));
        } else {
            $this->info(sprintf(
                'Module [%s] is already enabled.',
                $name
            ));
        }

        return self::SUCCESS;
    }
}