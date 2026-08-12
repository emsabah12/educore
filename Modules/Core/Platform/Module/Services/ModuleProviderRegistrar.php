<?php

declare(strict_types=1);

namespace Modules\Core\Platform\Module\Services;

use Illuminate\Contracts\Foundation\Application;
use Modules\Core\Platform\Module\Domain\ModuleDefinition;

final readonly class ModuleProviderRegistrar
{
    public function __construct(
        private Application $application,
    ) {}

    /**
     * Register providers declared by every installed non-Core module.
     *
     * Core is the bootstrap root and is registered by bootstrap/providers.php.
     *
     * @param iterable<ModuleDefinition> $definitions
     */
    public function register(iterable $definitions): void
    {
        foreach ($definitions as $definition) {
            if ($definition->name === 'core') {
                continue;
            }

            foreach ($definition->providers as $providerClass) {
                $this->application->register($providerClass);
            }
        }
    }
}
