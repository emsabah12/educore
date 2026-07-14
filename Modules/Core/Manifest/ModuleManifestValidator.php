<?php

declare(strict_types=1);

namespace Modules\Core\Manifest;

use Modules\Core\Exceptions\InvalidModuleManifestException;

final readonly class ModuleManifestValidator
{
    /**
     * Required fields based on current ADR.
     *
     * @var list<string>
     */
    private const REQUIRED_FIELDS = [
        'schema',
        'name',
        'display_name',
        'version',
        'description',
        'providers',
        'dependencies',
        'metadata',
        'extra',
    ];

    /**
     * Validate manifest structure.
     *
     * @param array<string, mixed> $manifest
     * @return array<string, mixed>
     *
     * @throws InvalidModuleManifestException
     */
    public function validate(array $manifest): array
    {
        $this->validateRequiredFields($manifest);

        $this->assertInteger($manifest, 'schema');

        $this->assertString($manifest, 'name');
        $this->assertString($manifest, 'display_name');
        $this->assertString($manifest, 'version');
        $this->assertString($manifest, 'description');

        $this->assertArray($manifest, 'providers');
        $this->assertArray($manifest, 'dependencies');
        $this->assertArray($manifest, 'metadata');
        $this->assertArray($manifest, 'extra');

        // PASTIKAN BARIS INI ADA DI SINI SEBELUM RETURN
        $this->assertValidServiceProviders($manifest);

        return $manifest;
    }

    /**
     * @param array<string, mixed> $manifest
     */
    private function validateRequiredFields(array $manifest): void
    {
        foreach (self::REQUIRED_FIELDS as $field) {
            if (!array_key_exists($field, $manifest)) {
                throw new InvalidModuleManifestException(
                    sprintf("Required field '%s' is missing.", $field)
                );
            }
        }
    }

    /**
     * @param array<string, mixed> $manifest
     */
    private function assertInteger(array $manifest, string $field): void
    {
        if (! is_int($manifest[$field])) {
            throw new InvalidModuleManifestException(
                sprintf(
                    "Field '%s' must be an integer.",
                    $field
                )
            );
        }
    }

    /**
     * @param array<string, mixed> $manifest
     */
    private function assertString(array $manifest, string $field): void
    {
        if (!is_string($manifest[$field])) {
            throw new InvalidModuleManifestException(
                sprintf("Field '%s' must be a string.", $field)
            );
        }
    }

     /**
     * @param array<string, mixed> $manifest
     */
    private function assertArray(array $manifest, string $field): void
    {
        if (!is_array($manifest[$field])) {
            throw new InvalidModuleManifestException(
                sprintf("Field '%s' must be an array.", $field)
            );
        }
    }


    /**
     * Memastikan string nama kelas Service Provider benar-benar terdaftar di autoloader PHP.
     * * @param array<string, mixed> $manifest
     * @throws InvalidModuleManifestException
     */
    private function assertValidServiceProviders(array $manifest): void
    {
        foreach ($manifest['providers'] as $providerClass) {
            if (!is_string($providerClass)) {
                throw new InvalidModuleManifestException(
                    sprintf("Module [%s] manifest error: Provider items must be strings.", $manifest['name'])
                );
            }

            // Memicu fail-fast jika developer modul salah ketik nama kelas provider di module.yaml
            if (!class_exists($providerClass)) {
                throw new InvalidModuleManifestException(
                    sprintf(
                        "Gagal memuat modul [%s]. Kelas Service Provider [%s] tidak ditemukan di sistem. Periksa kembali kemungkinan typo ejaan pada berkas module.yaml.",
                        $manifest['name'],
                        $providerClass
                    )
                );
            }
        }
    }
}
