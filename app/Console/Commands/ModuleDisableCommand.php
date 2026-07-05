<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Modules\Core\Exceptions\ModuleNotFoundException;
use Modules\Core\Services\ModuleManager;

final class ModuleDisableCommand extends Command
{
    protected $signature = 'module:disable {name : Module Name}';

    protected $description = 'Disable a module';

    public function handle(ModuleManager $manager): int
    {
        $name = strtolower((string) $this->argument('name'));

        try {
            $changed = $manager->disable($name);
        } catch (ModuleNotFoundException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        if ($changed) {
            $this->info(sprintf(
                'Module [%s] has been disabled.',
                $name
            ));
        } else {
            $this->info(sprintf(
                'Module [%s] is already disabled.',
                $name
            ));
        }

        return self::SUCCESS;
    }
}