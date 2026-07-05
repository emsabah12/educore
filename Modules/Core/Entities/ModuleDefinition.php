<?php

declare(strict_types=1);

namespace Modules\Core\Entities;

final readonly class ModuleDefinition
{
    /**
     * Core runtime representation of a module.
     */
    public function __construct(
        public int $schema,
        public string $name,
        public string $displayName,
        public string $version,
        public string $description,
        public array $providers = [],
        public array $dependencies = [],
        public array $metadata = [],
        public array $extra = [],
    ) {
    }

    /**
     * Factory method from validated manifest.
     *
     * @param array<string, mixed> $manifest
     */
    public static function fromArray(array $manifest): self
    {
        return new self(
            schema: $manifest['schema'],
            name: $manifest['name'],
            displayName: $manifest['display_name'],
            version: $manifest['version'],
            description: $manifest['description'],
            providers: $manifest['providers'] ?? [],
            dependencies: $manifest['dependencies'] ?? [],
            metadata: $manifest['metadata'] ?? [],
            extra: $manifest['extra'] ?? [],
        );
    }
}