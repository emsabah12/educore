<?php

declare(strict_types=1);

namespace Modules\Core\Manifest;

use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Yaml;

class ModuleManifestParser
{
    /**
     * Parse module.yaml menjadi array.
     *
     * @throws ParseException
     */
    public function parse(string $manifestPath): array
    {
        if (! is_file($manifestPath)) {
            throw new \InvalidArgumentException(
                sprintf('Manifest file not found: %s', $manifestPath)
            );
        }

        $manifest = Yaml::parseFile($manifestPath);

        return is_array($manifest)
            ? $manifest
            : [];
    }
}