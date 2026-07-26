<?php

declare(strict_types=1);

namespace Modules\Auth\Repositories;


use Illuminate\Support\Facades\DB;
use Modules\Auth\Authentication\Contracts\AuthenticationRepositoryInterface;

final class AuthenticationRepository implements AuthenticationRepositoryInterface
{
    private const USERS_TABLE = 'users';
    private const MEMBERSHIPS_TABLE = 'memberships';

    /**
     * Mencari user berdasarkan email global dan memastikan
     * user memiliki membership aktif pada tenant tertentu.
     *
     * @return array<string, mixed>|null
     */
    public function findByEmailForTenant(
        string $email,
        string $tenantUuid
    ): ?array {
        $normalizedEmail = strtolower(trim($email));

        $user = DB::table(self::USERS_TABLE)
            ->join(
                self::MEMBERSHIPS_TABLE,
                self::USERS_TABLE . '.id',
                '=',
                self::MEMBERSHIPS_TABLE . '.user_id'
            )
            ->select([
                self::USERS_TABLE . '.id',
                self::USERS_TABLE . '.name',
                self::USERS_TABLE . '.email',
                self::USERS_TABLE . '.password',
                self::USERS_TABLE . '.status as user_status',

                self::MEMBERSHIPS_TABLE . '.id as membership_id',
                self::MEMBERSHIPS_TABLE . '.tenant_id',
                self::MEMBERSHIPS_TABLE . '.role',
                self::MEMBERSHIPS_TABLE . '.status as membership_status',
            ])
            ->where(self::USERS_TABLE . '.email', $normalizedEmail)
            ->where(self::USERS_TABLE . '.status', 'ACTIVE')
            ->where(self::MEMBERSHIPS_TABLE . '.tenant_id', $tenantUuid)
            ->where(self::MEMBERSHIPS_TABLE . '.status', 'ACTIVE')
            ->first();

        return $user !== null
            ? (array) $user
            : null;
    }

    /**
     * Mengambil profil user global berdasarkan UUID.
     *
     * @return array<string, mixed>|null
     */
    public function findByUserUuid(string $userUuid): ?array
    {
        $user = DB::table(self::USERS_TABLE)
            ->where('id', $userUuid)
            ->where('status', 'ACTIVE')
            ->first();

        return $user !== null
            ? (array) $user
            : null;
    }
}
