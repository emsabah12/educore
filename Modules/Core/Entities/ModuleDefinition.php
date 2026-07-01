<?php

declare(strict_types=1);

namespace Modules\Core\Entities;

final readonly class ModuleDefinition
{
    public function __construct(
        private int $schema,
        private string $id,
        private string $name,
        private string $version,
        private string $description,
        private array $providers,
        private array $dependencies,
        private array $metadata,
        private array $extra,
    ) {
    }

    public function schema(): int
    {
        return $this->schema;
    }

    public function id(): string
    {
        return $this->id;
    }

    public function name(): string
    {
        return $this->name;
    }

    public function version(): string
    {
        return $this->version;
    }

    public function description(): string
    {
        return $this->description;
    }

    public function providers(): array
    {
        return $this->providers;
    }

    public function dependencies(): array
    {
        return $this->dependencies;
    }

    public function metadata(): array
    {
        return $this->metadata;
    }

    public function extra(): array
    {
        return $this->extra;
    }
}