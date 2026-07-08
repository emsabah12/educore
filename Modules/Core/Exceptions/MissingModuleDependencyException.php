<?php

declare(strict_types=1);

namespace Modules\Core\Exceptions;

use RuntimeException;

final class MissingModuleDependencyException extends RuntimeException
{
    public static function forModule(string $moduleName, string $missingDependency): self
    {
        return new self(sprintf(
            "Gagal memuat modul [%s] karena modul prasyarat (dependency) [%s] tidak ditemukan atau tidak aktif.",
            $moduleName,
            $missingDependency
        ));
    }
}