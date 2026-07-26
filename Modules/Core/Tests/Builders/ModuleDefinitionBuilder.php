<?php

declare(strict_types=1);

namespace Modules\Core\Tests\Builders;

use Modules\Core\Platform\Module\Domain\ModuleDefinition;

final class ModuleDefinitionBuilder
{
    /**
     * @param array<string,mixed> $attributes
     */
    private function __construct(
        private array $attributes,
    ) {}

    public static function make(): self
    {
        return new self([
            'schema' => 1,
            'name' => 'core',
            'display_name' => 'Core',
            'version' => '1.0.0',
            'description' => 'Core Module',
            'providers' => [],
            'dependencies' => [],
            'metadata' => [],
            'extra' => [],
        ]);
    }

    public function name(string $name): self
    {
        $clone = clone $this;
        $clone->attributes['name'] = $name;

        return $clone;
    }

    public function displayName(string $displayName): self
    {
        $clone = clone $this;
        $clone->attributes['display_name'] = $displayName;

        return $clone;
    }

    public function version(string $version): self
    {
        $clone = clone $this;
        $clone->attributes['version'] = $version;

        return $clone;
    }

    public function description(string $description): self
    {
        $clone = clone $this;
        $clone->attributes['description'] = $description;

        return $clone;
    }

    /**
     * @param array<int,string> $providers
     */
    public function providers(array $providers): self
    {
        $clone = clone $this;
        $clone->attributes['providers'] = $providers;

        return $clone;
    }

    /**
     * @param array<int,string> $dependencies
     */
    public function dependencies(array $dependencies): self
    {
        $clone = clone $this;
        $clone->attributes['dependencies'] = $dependencies;

        return $clone;
    }

    /**
     * @param array<string,mixed> $metadata
     */
    public function metadata(array $metadata): self
    {
        $clone = clone $this;
        $clone->attributes['metadata'] = $metadata;

        return $clone;
    }

    /**
     * @param array<string,mixed> $extra
     */
    public function extra(array $extra): self
    {
        $clone = clone $this;
        $clone->attributes['extra'] = $extra;

        return $clone;
    }

    public function schema(int $schema): self
    {
        $clone = clone $this;
        $clone->attributes['schema'] = $schema;

        return $clone;
    }

    public function build(): ModuleDefinition
    {
        return ModuleDefinition::fromArray($this->attributes);
    }
}
