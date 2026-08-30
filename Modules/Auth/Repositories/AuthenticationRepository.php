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
     * Resolve an active global User identity without Tenant/Membership
     * participation.
     *
     * Identifier classification is deterministic:
     *
     * - identifiers containing "@" are resolved by canonical email;
     * - all other identifiers are resolved by canonical username.
     *
     * The identifier is normalized again at this repository boundary so
     * callers outside the HTTP transport cannot bypass canonical lookup
     * normalization.
     *
     * @return array<string, mixed>|null
     */
    public function findActiveByLoginIdentifier(
        string $identifier,
    ): ?array {
        $normalizedIdentifier = strtolower(
            trim($identifier),
        );

        if ($normalizedIdentifier === '') {
            return null;
        }

        $loginColumn = str_contains(
            $normalizedIdentifier,
            '@',
        )
            ? 'email'
            : 'username';

        $user = DB::table(self::USERS_TABLE)
            ->join(
                self::PERSONS_TABLE,
                self::USERS_TABLE.'.person_id',
                '=',
                self::PERSONS_TABLE.'.id',
            )
            ->select([
                self::USERS_TABLE.'.id as user_id',
                self::USERS_TABLE.'.person_id',
                self::PERSONS_TABLE.'.name as person_name',
                self::USERS_TABLE.'.email',
                self::USERS_TABLE.'.username',
                self::USERS_TABLE.'.password as password_hash',
                self::USERS_TABLE.'.is_superadmin',
                self::USERS_TABLE.'.status as user_status',
            ])
            ->where(
                self::USERS_TABLE.'.'.$loginColumn,
                $normalizedIdentifier,
            )
            ->where(
                self::USERS_TABLE.'.status',
                'ACTIVE',
            )
            ->first();

        return $user !== null
            ? (array) $user
            : null;
    }

    /**
     * Legacy tenant-aware authentication lookup.
     *
     * Existing callers still depend on this method during the controlled
     * migration. New global authentication code must use
     * findActiveByLoginIdentifier().
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
                self::USERS_TABLE.'.person_id',
                '=',
                self::PERSONS_TABLE.'.id',
            )
            ->join(
                self::MEMBERSHIPS_TABLE,
                self::PERSONS_TABLE.'.id',
                '=',
                self::MEMBERSHIPS_TABLE.'.person_id',
            )
            ->join(
                self::TENANTS_TABLE,
                self::MEMBERSHIPS_TABLE.'.tenant_id',
                '=',
                self::TENANTS_TABLE.'.id',
            )
            ->select([
                self::USERS_TABLE.'.id',
                self::USERS_TABLE.'.person_id',
                self::PERSONS_TABLE.'.name',
                self::USERS_TABLE.'.email',
                self::USERS_TABLE.'.password',
                self::USERS_TABLE.'.status as user_status',
                self::MEMBERSHIPS_TABLE.'.id as membership_id',
                self::MEMBERSHIPS_TABLE.'.tenant_id',
                self::MEMBERSHIPS_TABLE.'.status as membership_status',
            ])
            ->where(
                self::USERS_TABLE.'.email',
                $normalizedEmail,
            )
            ->where(
                self::USERS_TABLE.'.status',
                'ACTIVE',
            )
            ->where(
                self::MEMBERSHIPS_TABLE.'.tenant_id',
                $tenantUuid,
            )
            ->where(
                self::MEMBERSHIPS_TABLE.'.status',
                'ACTIVE',
            )
            ->where(
                self::TENANTS_TABLE.'.is_active',
                true,
            )
            ->first();

        return $user !== null
            ? (array) $user
            : null;
    }
}
