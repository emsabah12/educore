<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Modules\Core\Exceptions\ModuleNotFoundException;
use Modules\Core\Services\ModuleManager;

final class ModuleDisableCommand extends Command
{
    protected $signature = 'module:disable {id : Module ID}';

    protected $description = 'Disable a module';

    public function handle(ModuleManager $manager): int
    {
        $id = strtolower((string) $this->argument('id'));

        try {
            $changed = $manager->disable($id);
        } catch (ModuleNotFoundException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        if ($changed) {
            $this->info(sprintf(
                'Module [%s] has been disabled.',
                $id
            ));
        } else {
            $this->info(sprintf(
                'Module [%s] is already disabled.',
                $id
            ));
        }

        return self::SUCCESS;
    }
}