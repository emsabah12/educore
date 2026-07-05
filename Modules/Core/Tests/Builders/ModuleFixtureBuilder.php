<?php

declare(strict_types=1);

namespace Modules\Core\Tests\Builders;

use Modules\Core\Tests\Fixtures\ModuleFixture;

final class ModuleFixtureBuilder
{
    private string $name = 'Core';

    /**
     * @var list<string>
     */
    private array $directories = [];

    /**
     * @var array<string,string>
     */
    private array $files = [];

    public static function make(): self
    {
        return new self();
    }

    public function manifest(ManifestBuilder $manifest): self
    {
        $clone = clone $this;
        $clone->name = $manifest->moduleName();    
    
        $clone->files['module.yaml'] = $manifest->toYaml();

        return $clone;
    }

    public function addDirectory(string $directory): self
    {
        $clone = clone $this;
        if (! in_array($directory, $clone->directories, true)) {
            $clone->directories[] = $directory;
        }

        return $clone;
    }

    public function addFile(string $path, string $contents): self
    {
        $clone = clone $this;
        $clone->files[$path] = $contents;

        return $clone;
    }

    public function build(): ModuleFixture
    {
        return new ModuleFixture(
            name: $this->name,
            directories: $this->directories,
            files: $this->files,
        );
    }
}