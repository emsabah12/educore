<?php

declare(strict_types=1);

namespace Modules\Core\Identity\Contracts;

use Modules\Core\Identity\Models\User;

interface ActiveUserResolverInterface
{
    /**
     * Resolve canonical active global user berdasarkan UUID.
     *
     * Identifier invalid, user tidak ditemukan, atau user non-ACTIVE
     * selalu menghasilkan null.
     */
    public function findActiveById(
        string $userId,
    ): ?User;
}
