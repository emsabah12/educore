<?php

declare(strict_types=1);

namespace Modules\Core\Manifest;

use InvalidArgumentException;
use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Yaml;

final readonly class ModuleManifestParser
{
    /**
     * Parse YAML manifest content into PHP array.
     *
     * @throws InvalidArgumentException
     */
    public function parse(string $content): array
    {
        try {
            $manifest = Yaml::parse($content);
        } catch (ParseException $exception) {
            throw new InvalidArgumentException(
                'Invalid module manifest YAML.',
                previous: $exception
            );
        }

        if (! is_array($manifest)) {
            throw new InvalidArgumentException(
                'Module manifest must be a YAML object.'
            );
        }

        return $manifest;
    }
}
