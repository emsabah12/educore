<?php

declare(strict_types=1);

namespace Modules\Auth\Repositories;

use Illuminate\Support\Facades\DB;
use Modules\Auth\Authentication\Contracts\AuthenticationRepositoryInterface;

final class AuthenticationRepository implements AuthenticationRepositoryInterface
{
    private const USERS_TABLE = 'users';
    private const PERSONS_TABLE = 'persons';
    private const MEMBERSHIPS_TABLE = 'memberships';
    private const TENANTS_TABLE = 'tenants';

    /**
     * Find an active account by login email and ensure its canonical Person
     * owns an active membership in the requested active tenant.
     *
     * Authorization roles are intentionally not read here.
     *
     * @return array<string, mixed>|null
     */
    public function findByEmailForTenant(
        string $email,
        string $tenantUuid,
    ): ?array {
        $normalizedEmail = strtolower(trim($email));
        $tenantUuid = trim($tenantUuid);

        if (
            $normalizedEmail === ''
            || $tenantUuid === ''
        ) {
            return null;
        }

        $user = DB::table(self::USERS_TABLE)
            ->join(
                self::PERSONS_TABLE,
                self::USERS_TABLE . '.person_id',
                '=',
                self::PERSONS_TABLE . '.id',
            )
            ->join(
                self::MEMBERSHIPS_TABLE,
                self::PERSONS_TABLE . '.id',
                '=',
                self::MEMBERSHIPS_TABLE . '.person_id',
            )
            ->join(
                self::TENANTS_TABLE,
                self::MEMBERSHIPS_TABLE . '.tenant_id',
                '=',
                self::TENANTS_TABLE . '.id',
            )
            ->select([
                self::USERS_TABLE . '.id',
                self::USERS_TABLE . '.person_id',
                self::PERSONS_TABLE . '.name',
                self::USERS_TABLE . '.email',
                self::USERS_TABLE . '.password',
                self::USERS_TABLE . '.status as user_status',
                self::MEMBERSHIPS_TABLE . '.id as membership_id',
                self::MEMBERSHIPS_TABLE . '.tenant_id',
                self::MEMBERSHIPS_TABLE . '.status as membership_status',
            ])
            ->where(
                self::USERS_TABLE . '.email',
                $normalizedEmail,
            )
            ->where(
                self::USERS_TABLE . '.status',
                'ACTIVE',
            )
            ->where(
                self::MEMBERSHIPS_TABLE . '.tenant_id',
                $tenantUuid,
            )
            ->where(
                self::MEMBERSHIPS_TABLE . '.status',
                'ACTIVE',
            )
            ->where(
                self::TENANTS_TABLE . '.is_active',
                true,
            )
            ->first();

        return $user !== null
            ? (array) $user
            : null;
    }
}
