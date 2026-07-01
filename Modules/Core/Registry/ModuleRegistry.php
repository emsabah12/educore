<?php

declare(strict_types=1);

namespace Modules\Core\Registry;

use Modules\Core\Entities\ModuleDefinition;
use RuntimeException;
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
        $id = $module->id();

        if ($this->has($id)) {
            throw new ModuleAlreadyRegisteredException(
                sprintf("Module '%s' is already registered.", $id)
            );
        }

        $this->modules[$id] = $module;
    }

    public function has(string $id): bool
    {
        return array_key_exists($id, $this->modules);
    }

    public function get(string $id): ModuleDefinition
    {
        if (! $this->has($id)) {
            throw new ModuleNotFoundException(
                sprintf("Module '%s' is not registered.", $id)
            );
        }

        return $this->modules[$id];
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