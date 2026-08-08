<?php

declare(strict_types=1);

namespace Modules\Core\Identity\Infrastructure;

use Illuminate\Support\Str;
use Modules\Core\Identity\Contracts\ActiveUserResolverInterface;
use Modules\Core\Identity\Models\User;

final class EloquentActiveUserResolver implements ActiveUserResolverInterface
{
    public function findActiveById(
        string $userId,
    ): ?User {
        $userId = trim($userId);

        if (
            $userId === ''
            || ! Str::isUuid($userId)
        ) {
            return null;
        }

        return User::query()
            ->whereKey($userId)
            ->where('status', 'ACTIVE')
            ->first();
    }
}
