<?php

declare(strict_types=1);

namespace Modules\Core\Manifest;

use RuntimeException;

final readonly class ModuleManifestLoader
{
    /**
     * Membaca isi file manifest.
     *
     * @throws RuntimeException
     */
    public function load(string $manifestPath): string
    {
        if (! is_file($manifestPath)) {
            throw new RuntimeException(
                sprintf(
                    'Manifest file not found: %s',
                    $manifestPath
                )
            );
        }

        $contents = file_get_contents($manifestPath);

        if ($contents === false) {
            throw new RuntimeException(
                sprintf(
                    'Unable to read manifest file: %s',
                    $manifestPath
                )
            );
        }

        return $contents;
    }
}