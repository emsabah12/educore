<?php

declare(strict_types=1);

namespace Modules\Core\Identity\Infrastructure;

use Modules\Core\Identity\Contracts\ActiveUserResolverInterface;
use Modules\Core\Identity\Models\User;
use Modules\Core\Support\Uuid\UuidV7;

final class EloquentActiveUserResolver implements ActiveUserResolverInterface
{
    public function findActiveById(
        string $userId,
    ): ?User {
        $userId = trim($userId);

        if (! UuidV7::validate($userId)) {
            return null;
        }

        return User::query()
            ->with('person')
            ->whereKey($userId)
            ->where('status', 'ACTIVE')
            ->first();
    }
}
