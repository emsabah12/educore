<?php

declare(strict_types=1);

namespace Modules\Core\Exceptions;

use RuntimeException;

final class CircularDependencyException extends RuntimeException
{
    public static function forModule(string $moduleName, string $path): self
    {
        return new self(sprintf(
            "Terdeteksi Circular Dependency (Ketergantungan Melingkar) pada modul [%s]. Alur siklus: %s",
            $moduleName,
            $path
        ));
    }
}