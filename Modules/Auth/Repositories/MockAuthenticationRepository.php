<?php

declare(strict_types=1);

namespace Modules\Auth\Repositories;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Modules\Auth\Authentication\Contracts\AuthenticationRepositoryInterface;

final class MockAuthenticationRepository implements AuthenticationRepositoryInterface
{
    /**
     * Simulasi identity user untuk kebutuhan local development.
     *
     * Catatan:
     * Role TIDAK disimpan di mock authentication identity.
     * Authorization role harus diselesaikan oleh Authorization layer.
     *
     * @var array<int, array<string, mixed>>
     */
    private array $mockUsers = [];

    public function __construct()
    {
        $this->mockUsers = [
            [
                'id' => '019f6e4d-c4cc-72c2-b8d7-cd6faabe6fd2',
                'name' => 'Super Administrator Global',
                'email' => 'superadmin@educore.id',
                'password' => Hash::make('secretpassword'),
                'tenant_id' => '019f62f3-f5b5-7216-9578-0af9cb3b5b54',
                'membership_id' => '019f6e4d-c67c-7064-a1d5-5261c4162922',
                'status' => 'ACTIVE',
            ],
        ];
    }

    /**
     * Mencari identity berdasarkan email dan tenant context.
     *
     * Authentication hanya mengembalikan:
     * - User identity
     * - Membership identity
     * - Tenant context
     *
     * Tidak mengembalikan role authorization.
     *
     * @return array<string, mixed>|null
     */
    public function findByEmailForTenant(
        string $email,
        string $tenantUuid
    ): ?array {
        $normalizedEmail = strtolower(trim($email));

        if (app()->environment('testing')) {
            $dbUser = $this->queryDatabaseByEmail(
                $normalizedEmail,
                $tenantUuid
            );

            if ($dbUser !== null) {
                return $dbUser;
            }
        }

        foreach ($this->mockUsers as $user) {
            if (
                strtolower((string) $user['email']) === $normalizedEmail
                && (string) $user['tenant_id'] === $tenantUuid
            ) {
                return $user;
            }
        }

        return $this->queryDatabaseByEmail(
            $normalizedEmail,
            $tenantUuid
        );
    }

    /**
     * Mencari identity global berdasarkan UUID user.
     *
     * @return array<string, mixed>|null
     */
    public function findByUserUuid(string $userUuid): ?array
    {
        if (app()->environment('testing')) {
            $dbUser = $this->queryDatabaseByUuid($userUuid);

            if ($dbUser !== null) {
                return $dbUser;
            }
        }

        foreach ($this->mockUsers as $user) {
            if ((string) $user['id'] === $userUuid) {
                return $user;
            }
        }

        return $this->queryDatabaseByUuid($userUuid);
    }

    /**
     * Query database berdasarkan email + tenant.
     *
     * Membership role sengaja tidak di-select.
     *
     * @return array<string, mixed>|null
     */
    private function queryDatabaseByEmail(
        string $email,
        string $tenantUuid
    ): ?array {
        $dbUser = DB::table('users')
            ->join(
                'memberships',
                'users.id',
                '=',
                'memberships.user_id'
            )
            ->select([
                'users.id',
                'users.name',
                'users.email',
                'users.password',
                'users.status as user_status',

                'memberships.tenant_id',
                'memberships.id as membership_id',
                'memberships.status as membership_status',
            ])
            ->where(
                'users.email',
                $email
            )
            ->where(
                'users.status',
                'ACTIVE'
            )
            ->where(
                'memberships.tenant_id',
                $tenantUuid
            )
            ->where(
                'memberships.status',
                'ACTIVE'
            )
            ->first();

        return $dbUser !== null
            ? (array) $dbUser
            : null;
    }

    /**
     * Query identity global berdasarkan UUID.
     *
     * Tidak melakukan join ke memberships karena identity
     * global tidak membutuhkan authorization context.
     *
     * @return array<string, mixed>|null
     */
    private function queryDatabaseByUuid(
        string $userUuid
    ): ?array {
        $dbUser = DB::table('users')
            ->select([
                'id',
                'name',
                'email',
                'password',
                'status',
            ])
            ->where(
                'id',
                $userUuid
            )
            ->where(
                'status',
                'ACTIVE'
            )
            ->first();

        return $dbUser !== null
            ? (array) $dbUser
            : null;
    }
}
