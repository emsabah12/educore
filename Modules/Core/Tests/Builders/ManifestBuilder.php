<?php

declare(strict_types=1);

namespace Modules\Core\Tests\Builders;

use Symfony\Component\Yaml\Yaml;

final class ManifestBuilder
{
    private int $schema = 1;

    private string $name = 'Core';

    private string $displayName = 'Core';
    
    private string $version = '1.0.0';
    
    private string $description = 'Core Module';

    /**
     * @var list<string>
     */
    private array $providers = [];

    /**
     * @var list<string>
     */
    private array $dependencies = [];

    /**
     * @var list<string>
     */
    private array $metadata = [];

    /**
     * @var list<string>
     */
    private array $extra = [];

    private function __construct()
    {
    }

    public static function make(): self
    {
        return new self();
    }

    public function schema(int $schema): self
    {
        $clone = clone $this;
        $clone->schema = $schema;

        return $clone;
    }

    public function name(string $name): self
    {
        $clone = clone $this;
        $clone->name = $name;

        return $clone;
    }

    public function moduleName(): string
    {
        return $this->name;
    }

    public function displayName(string $displayName): self
    {
        $clone = clone $this;
        $clone->displayName = $displayName;

        return $clone;
    }

    public function description(string $description): self
    {
        $clone = clone $this;
        $clone->description = $description;

        return $clone;
    }

    public function version(string $version): self
    {
        $clone = clone $this;
        $clone->version = $version;

        return $clone;
    }

    /**
     * @param list<string> $providers
     */
    public function providers(array $providers): self
    {
        $clone = clone $this;
        $clone ->providers = $providers;

        return $clone;
    }

    /**
}
     * @param list<string, mixed> $metadata
     */
    public function metadata(array $metadata): self
    {
        $clone = clone $this;
        $clone->metadata = $metadata;

        return $clone;
    }

    /**
     * @param list<string, mixed> $dependencies
     */
    public function dependencies(array $dependencies): self
    {
        $clone = clone $this;
        $clone->dependencies = $dependencies;

        return $clone;
    }

    /**
     * @param list<string, mixed> $extra
     */
    public function extra(array $extra): self
    {   
        $clone = clone $this;
        $clone->extra = $extra;

        return $clone;
    }

    /**
 * @return array<string, mixed>
 */
public function build(): array
{
    return [
        'schema' => $this->schema,
        'name' => $this->name,
        'display_name' => $this->displayName,
        'version' => $this->version,
        'description' => $this->description,
        'providers' => $this->providers,
        'dependencies' => $this->dependencies,
        'metadata' => $this->metadata,
        'extra' => $this->extra,
    ];
}

    public function toYaml(): string
    {
        return Yaml::dump(
            $this->build(),
            4,
            2,
            Yaml::DUMP_MULTI_LINE_LITERAL_BLOCK
        );
    }
}