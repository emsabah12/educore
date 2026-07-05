<?php

declare(strict_types=1);

namespace Modules\Core\Support\Uuid;

use Symfony\Component\Uid\Uuid;

final class UuidGenerator
{
    public static function v7(): string
    {
        return Uuid::v7()->toRfc4122();
    }
}