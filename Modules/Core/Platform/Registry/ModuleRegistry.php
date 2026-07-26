<?php

declare(strict_types=1);

namespace Modules\Core\Platform\Registry;

use Modules\Core\Platform\Module\Domain\ModuleDefinition;
use Modules\Core\Services\ModuleBootstrapService;
use Modules\Core\Exceptions\ModuleAlreadyRegisteredException;
use Modules\Core\Exceptions\ModuleNotFoundException;

final class ModuleRegistry
{
    /**
     * @var array<string, ModuleDefinition>
     */
    private array $modules = [];

    public function register(ModuleDefinition $module): void
    {
        $name = $module->name;

        if ($this->has($name)) {
            throw new ModuleAlreadyRegisteredException(
                sprintf(
                    "Module '%s' is already registered.",
                    $name
                )
            );
        }

        $this->modules[$name] = $module;
    }

    public function has(string $name): bool
    {
        return array_key_exists($name, $this->modules);
    }

    public function get(string $name): ModuleDefinition
    {
        if (! $this->has($name)) {
            throw new ModuleNotFoundException(
                sprintf(
                    "Module '%s' not found.",
                    $name
                )
            );
        }

        return $this->modules[$name];
    }

    /**
     * @return array<string, ModuleDefinition>
     */
    public function all(): array
    {
        return $this->modules;
    }

    public function count(): int
    {
        return count($this->modules);
    }
}
