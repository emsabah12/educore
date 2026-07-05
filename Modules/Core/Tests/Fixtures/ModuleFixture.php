<?php

declare(strict_types=1);

namespace Modules\Core\Tests\Fixtures;

/**
 * Immutable representation of a module fixture used for testing.
 */
final readonly class ModuleFixture
{
    /**
     * @param list<string> $directories
     * @param array<string, string> $files
     */
    public function __construct(
        public readonly string $name,
        public readonly array $directories = [],
        public readonly array $files = [],
    ) {
    }

    /**
     * Check whether the fixture contains a file.
     */
    public function hasFile(string $path): bool
    {
        return array_key_exists($path, $this->files);
    }

    /**
     * Get file content or null if it does not exist.
     */
    public function file(string $path): ?string
    {
        return $this->files[$path] ?? null;
    }

    /**
     * Check whether the fixture contains a directory.
     */
    public function hasDirectory(string $path): bool
    {
        return in_array($path, $this->directories, true);
    }
}