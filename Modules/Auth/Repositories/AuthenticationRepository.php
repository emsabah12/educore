<?php

declare(strict_types=1);

namespace Modules\Auth\Repositories;

use Modules\Core\Contracts\Auth\AuthenticationRepositoryInterface;

final class AuthenticationRepository implements AuthenticationRepositoryInterface
{
    /**
     * Data simulasi lokal dengan enkapsulasi UUID v7 dan isolasi tenant.
     * Password di-hash menggunakan bcrypt bawaan dengan password default: 'secretpassword'
     */
    private static array $mockStorage = [
        [
            'uuid'        => '018f3b20-6d80-7111-a832-65a8df397a7a',
            'tenant_uuid' => '018f3b20-6d80-7222-b943-76b9ef408b8b', // Tenant Yayasan A
            'name'        => 'Administrator Yayasan A',
            'email'       => 'admin.a@educore.id',
            'password'    => '$2y$12$eImiTXtA9.vX6KzBshL70OaK581wFkWmXoex4D05kXp8uM8gX6E2y',
            'status'      => 'ACTIVE'
        ],
        [
            'uuid'        => '018f3b20-6d80-7333-c054-87ca0f419c9c',
            'tenant_uuid' => '018f3b20-6d80-7444-d165-98db1f52adad', // Tenant Yayasan B
            'name'        => 'Administrator Yayasan B',
            'email'       => 'admin.b@educore.id',
            'password'    => '$2y$12$eImiTXtA9.vX6KzBshL70OaK581wFkWmXoex4D05kXp8uM8gX6E2y',
            'status'      => 'ACTIVE'
        ]
    ];

    /**
     * {@inheritdoc}
     */
    public function findByEmailForTenant(string $email, string $tenantUuid): ?array
    {
        foreach (self::$mockStorage as $user) {
            if (strtolower($user['email']) === strtolower($email) && $user['tenant_uuid'] === $tenantUuid) {
                return $user;
            }
        }
        return null;
    }

    /**
     * {@inheritdoc}
     */
    public function findByUserUuid(string $userUuid): ?array
    {
        foreach (self::$mockStorage as $user) {
            if ($user['uuid'] === $userUuid) {
                return $user;
            }
        }
        return null;
    }
}
