<?php

declare(strict_types=1);

namespace Modules\Core\Platform\Discovery;

use FilesystemIterator;

final class ModuleDiscovery
{
    public function discover(string $modulesPath): array
    {
        if (! is_dir($modulesPath)) {
            return [];
        }

        $manifests = [];

        $directories = new FilesystemIterator(
            $modulesPath,
            FilesystemIterator::SKIP_DOTS
        );

        foreach ($directories as $directory) {
            if (! $directory->isDir()) {
                continue;
            }

            $manifest = $directory->getPathname() . DIRECTORY_SEPARATOR . 'module.yaml';

            if (is_file($manifest)) {
                $manifests[] = $manifest;
            }
        }

        sort($manifests);

        return $manifests;
    }
}
