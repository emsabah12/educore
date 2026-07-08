<?php

declare(strict_types=1);

namespace Modules\Core\Support\Uuid;

use Illuminate\Database\Schema\Blueprint;

final readonly class UuidBlueprintMacro
{
    /**
     * Mendaftarkan macro UUID v7 ke dalam Blueprint Schema Laravel.
     */
    public static function register(): void
    {
        // 1. Macro untuk Primary Key UUID v7
        Blueprint::macro('uuid7', function (string $column = 'id'): Blueprint {
            /** @var Blueprint $this */
            $this->uuid($column)->primary();
            return $this;
        });

        // 2. Macro untuk Foreign Key UUID v7
        Blueprint::macro('foreignUuid7', function (string $column): Blueprint {
            /** @var Blueprint $this */
            $this->uuid($column);
            return $this;
        });
        // 3. Tambahan alias jika ada berkas yang memanggil uuidV7 (opsional untuk fail-safe)
        Blueprint::macro('uuidV7', function (string $column = 'id') {
            /** @var Blueprint $this */
            $this->uuid($column);
            return $this;
        });
    }
}