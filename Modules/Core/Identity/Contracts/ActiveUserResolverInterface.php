<?php

declare(strict_types=1);

namespace Modules\Core\Identity\Contracts;

use Modules\Core\Identity\Models\User;

interface ActiveUserResolverInterface
{
    /**
     * Resolve canonical active global User account berdasarkan UUIDv7.
     *
     * Identifier bukan UUIDv7, user tidak ditemukan, atau user non-ACTIVE
     * selalu menghasilkan null.
     */
    public function findActiveById(
        string $userId,
    ): ?User;
}
