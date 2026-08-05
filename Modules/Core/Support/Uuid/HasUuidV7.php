<?php

declare(strict_types=1);

namespace Modules\Core\Support\Uuid;

use Illuminate\Database\Eloquent\Model;

trait HasUuidV7
{
    /**
     * Menghasilkan UUIDv7 ketika model baru belum memiliki primary key.
     *
     * ID yang sudah diberikan oleh caller tidak akan ditimpa.
     */
    protected static function bootHasUuidV7(): void
    {
        static::creating(
            static function (Model $model): void {
                if ($model->getIncrementing()) {
                    return;
                }

                $keyName = $model->getKeyName();
                $currentKey = $model->getAttribute(
                    $keyName,
                );

                if (
                    $currentKey !== null
                    && $currentKey !== ''
                ) {
                    return;
                }

                $model->setAttribute(
                    $keyName,
                    UuidV7::generate(),
                );
            },
        );
    }

    /**
     * Model UUID tidak memakai auto-incrementing integer.
     */
    public function getIncrementing(): bool
    {
        return false;
    }

    /**
     * Primary key UUID direpresentasikan sebagai string.
     */
    public function getKeyType(): string
    {
        return 'string';
    }
}
