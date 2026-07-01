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
    public function isEnabled(string $moduleId): bool
    {
        $this->registry->get($moduleId);

        return $this->stateRepository->isEnabled($moduleId);
    }

    /**
     * Enable a module.
     *
     * @throws ModuleNotFoundException
     */
    public function enable(string $moduleId): bool
    {
        $this->registry->get($moduleId);

        if ($this->stateRepository->isEnabled($moduleId)) {
            return false;
        }

        $this->stateRepository->enable($moduleId);

        return true;
    }

    /**
     * Disable a module.
     *
     * @throws ModuleNotFoundException
     */
    public function disable(string $moduleId): bool
    {
        $this->registry->get($moduleId);

        if (! $this->stateRepository->isEnabled($moduleId)) {
            return false;
        }

        $this->stateRepository->disable($moduleId);

        return true;
    }
}