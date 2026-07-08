<?php

declare(strict_types=1);

namespace Modules\Core\Support\Uuid;

use Illuminate\Support\Str;
use Illuminate\Database\Eloquent\Model;

trait HasUuidV7
{
    /**
     * Mencegat siklus hidup booting model Laravel untuk menyuntikkan UUID v7 secara otomatis.
     * Laravel secara otomatis mengeksekusi metode dengan konvensi nama 'boot[NamaTrait]'.
     */
    protected static function bootHasUuidV7(): void
    {
        static::creating(function (Model $model) {
            // Evaluasi apakah model dikonfigurasi untuk menggunakan incrementing integer biasa atau tidak
            if (! $model->getIncrementing()) {
                $keyName = $model->getKeyName();
                
                // Menyuntikkan UUID v7 hanya jika primary key belum diisi secara manual
                if (empty($model->getAttribute($keyName))) {
                    $model->setAttribute($keyName, Str::uuid7()->toString());
                }
            }
        });
    }

    /**
     * Menegaskan ke framework Laravel bahwa model ini tidak menggunakan auto-incrementing integer.
     */
    public function getIncrementing(): bool
    {
        return false;
    }

    /**
     * Menegaskan tipe data Primary Key yang digunakan pada level model adalah string.
     */
    public function getKeyType(): string
    {
        return 'string';
    }
}