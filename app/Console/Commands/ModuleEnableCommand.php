<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Modules\Core\Exceptions\ModuleNotFoundException;
use Modules\Core\Services\ModuleManager;

final class ModuleEnableCommand extends Command
{
    protected $signature = 'module:enable {id : Module ID}';

    protected $description = 'Enable a module';

    public function handle(ModuleManager $manager): int
    {
        $id = strtolower((string) $this->argument('id'));

        try {
            $changed = $manager->enable($id);
        } catch (ModuleNotFoundException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        if ($changed) {
            $this->info(sprintf(
                'Module [%s] has been enabled.',
                $id
            ));
        } else {
            $this->info(sprintf(
                'Module [%s] is already enabled.',
                $id
            ));
        }

        return self::SUCCESS;
    }
}