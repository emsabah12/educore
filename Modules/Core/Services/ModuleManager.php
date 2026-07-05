<?php

declare(strict_types=1);

namespace Modules\Core\Services;

use Modules\Core\Exceptions\ModuleNotFoundException;
use Modules\Core\Registry\ModuleRegistry;

final readonly class ModuleManager
{
    public function __construct(
        private ModuleRegistry $registry,
        private ModuleStateRepository $stateRepository,
    ) {
    }

    /**
     * Determine whether a module is enabled.
     *
     * @throws ModuleNotFoundException
     */
    public function isEnabled(string $moduleName): bool
    {
        $this->registry->get($moduleName);

        return $this->stateRepository->isEnabled($moduleName);
    }

    /**
     * Enable a module.
     *
     * @throws ModuleNotFoundException
     */
    public function enable(string $moduleName): bool
    {
        $this->registry->get($moduleName);

        if ($this->stateRepository->isEnabled($moduleName)) {
            return false;
        }

        $this->stateRepository->enable($moduleName);

        return true;
    }

    /**
     * Disable a module.
     *
     * @throws ModuleNotFoundException
     */
    public function disable(string $moduleName): bool
    {
        $this->registry->get($moduleName);

        if (! $this->stateRepository->isEnabled($moduleName)) {
            return false;
        }

        $this->stateRepository->disable($moduleName);

        return true;
    }
}