<?php

declare(strict_types=1);

namespace Modules\Core\Platform\Console;

use Illuminate\Console\Command;
use Modules\Core\Services\ModuleRepository;
use Throwable;

class ModuleStatusCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'module:status {name : The technical identifier of the module}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Display manifest metadata for a discovered module';

    /**
     * Execute the console command.
     */
    public function handle(ModuleRepository $moduleRepository): int
    {
        $moduleName = trim((string) $this->argument('name'));

        if ($moduleName === '') {
            $this->components->error('Module name must not be empty.');

            return self::FAILURE;
        }

        try {
            $module = $moduleRepository->find($moduleName);

            if ($module === null) {
                $this->components->error(
                    sprintf(
                        'Module "%s" is not registered or cannot be discovered.',
                        $moduleName,
                    ),
                );

                return self::FAILURE;
            }

            $this->components->info(
                sprintf('Module Manifest Profile: %s', $module->name),
            );

            $this->components->twoColumnDetail(
                'Technical Identifier',
                $module->name,
            );
            $this->components->twoColumnDetail(
                'Display Name',
                $module->displayName,
            );
            $this->components->twoColumnDetail(
                'Current Version',
                $module->version,
            );
            $this->components->twoColumnDetail(
                'Description',
                $module->description,
            );
            $this->components->twoColumnDetail(
                'Dependencies',
                $module->dependencies === []
                    ? '-'
                    : implode(', ', $module->dependencies),
            );
            $this->components->twoColumnDetail(
                'Providers',
                $module->providers === []
                    ? '-'
                    : implode(', ', $module->providers),
            );

            return self::SUCCESS;
        } catch (Throwable $exception) {
            report($exception);

            $this->components->error(
                sprintf(
                    'Unable to read module manifest metadata for "%s". Check the application logs for details.',
                    $moduleName,
                ),
            );

            return self::FAILURE;
        }
    }
}
