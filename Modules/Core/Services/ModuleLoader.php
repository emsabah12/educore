<?php

declare(strict_types=1);

namespace Modules\Core\Services;

use Modules\Core\Entities\ModuleDefinition;
use Modules\Core\Registry\ModuleRegistry;

final readonly class ModuleLoader
{
    public function __construct(
        private ModuleRegistry $registry,
    ) {
    }

    /**
     * Register all module definitions into the registry.
     *
     * @param iterable<ModuleDefinition> $definitions
     */
    public function load(iterable $definitions): ModuleRegistry
    {
        foreach ($definitions as $definition) {
            $this->registry->register($definition);
        }

        return $this->registry;
    }
}