<?php

declare(strict_types=1);

namespace Modules\Core\Manifest;

use Modules\Core\Exceptions\InvalidModuleManifestException;

final class ManifestValidator
{
    /**
     * Field wajib pada module.yaml.
     */
    private const REQUIRED_FIELDS = [
        'schema',
        'id',
        'name',
        'version',
        'description',
        'providers',
        'dependencies',
        'metadata',
        'extra',
    ];

    /**
     * Validasi manifest.
     *
     * @throws InvalidModuleManifestException
     */
    public function validate(array $manifest): void
    {
        foreach (self::REQUIRED_FIELDS as $field) {
            if (!array_key_exists($field, $manifest)) {
                throw new InvalidModuleManifestException(
                    sprintf("Required field '%s' is missing.", $field)
                );
            }
        }

        $this->assertType($manifest, 'schema', 'integer');
        $this->assertType($manifest, 'id', 'string');
        $this->assertType($manifest, 'name', 'string');
        $this->assertType($manifest, 'version', 'string');
        $this->assertType($manifest, 'description', 'string');
        $this->assertType($manifest, 'providers', 'array');
        $this->assertType($manifest, 'dependencies', 'array');
        $this->assertType($manifest, 'metadata', 'array');
        $this->assertType($manifest, 'extra', 'array');
    }

    private function assertType(array $manifest, string $field, string $type): void
    {
        $value = $manifest[$field];

        $valid = match ($type) {
            'string' => is_string($value),
            'integer' => is_int($value),
            'array' => is_array($value),
            default => false,
        };

        if (!$valid) {
            throw new InvalidModuleManifestException(
                sprintf("Field '%s' must be of type %s.", $field, $type)
            );
        }
    }
}