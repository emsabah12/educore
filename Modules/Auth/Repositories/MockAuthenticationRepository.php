<?php

declare(strict_types=1);

namespace Modules\Auth\Repositories;

use Modules\Auth\Authentication\Contracts\AuthenticationRepositoryInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

final class MockAuthenticationRepository implements AuthenticationRepositoryInterface
{
    /**
     * Simulasi penyimpanan data statis user di memori runtime untuk kebutuhan lokal development cepat.
     */
    private array $mockUsers = [];

    /**
     * Inisialisasi data tiruan dengan hash dinamis agar kompatibel dengan runtime CLI/Tinker lokal.
     */
    public function __construct()
    {
        $this->mockUsers = [
            [
                'id' => '019f6e4d-c4cc-72c2-b8d7-cd6faabe6fd2',
                'name' => 'Super Administrator Global',
                'email' => 'superadmin@educore.id',
                // Di-hash saat bootstrap runtime agar salt sinkron sempurna dengan driver lokal
                'password' => Hash::make('secretpassword'),
                'tenant_id' => '019f62f3-f5b5-7216-9578-0af9cb3b5b54',
                'membership_id' => '019f6e4d-c67c-7064-a1d5-5261c4162922',
                'role' => 'PEGAWAI',
                'status' => 'ACTIVE'
            ]
        ];
    }

    /**
     * Mencari data entitas gabungan User dan Membership berdasarkan kriteria email dan tenant.
     */
    public function findByEmailForTenant(string $email, string $tenantUuid): ?array
    {
        // JIKA DI LINGKUNGAN TESTING: Dahulukan kueri database untuk menangkap data fixture dinamis
        if (app()->environment('testing')) {
            $dbUser = $this->queryDatabaseByEmail($email, $tenantUuid);
            if ($dbUser) {
                return $dbUser;
            }
        }

        // LOKAL DEVELOPMENT MODE: Cari di dalam Array Statis Memori
        foreach ($this->mockUsers as $user) {
            if (
                strtolower($user['email']) === strtolower($email) &&
                $user['tenant_id'] === $tenantUuid
            ) {
                return $user;
            }
        }

        // FALLBACK AKHIR: Jika data di array statis tidak ada, cari di database lokal real jika tersedia.
        return $this->queryDatabaseByEmail($email, $tenantUuid);
    }

    /**
     * Mencari identitas berdasarkan UUID Pengguna Global.
     */
    public function findByUserUuid(string $userUuid): ?array
    {
        if (app()->environment('testing')) {
            $dbUser = $this->queryDatabaseByUuid($userUuid);
            if ($dbUser) {
                return $dbUser;
            }
        }

        foreach ($this->mockUsers as $user) {
            if ($user['id'] === $userUuid) {
                return $user;
            }
        }

        return $this->queryDatabaseByUuid($userUuid);
    }

    /**
     * Helper enkapsulasi kueri database berdasarkan kriteria Email & Tenant (DRY Principle).
     */
    private function queryDatabaseByEmail(string $email, string $tenantUuid): ?array
    {
        $dbUser = DB::table('users')
            ->join('memberships', 'users.id', '=', 'memberships.user_id')
            ->select([
                'users.id',
                'users.name',
                'users.email',
                'users.password',
                'memberships.tenant_id',
                'memberships.id as membership_id',
                'memberships.role',
                'memberships.status'
            ])
            ->where('users.email', '=', strtolower($email))
            ->where('memberships.tenant_id', '=', $tenantUuid)
            ->first();

        return $dbUser ? (array) $dbUser : null;
    }

    /**
     * Helper enkapsulasi kueri database berdasarkan UUID User (DRY Principle).
     */
    private function queryDatabaseByUuid(string $userUuid): ?array
    {
        $dbUser = DB::table('users')
            ->join('memberships', 'users.id', '=', 'memberships.user_id')
            ->select([
                'users.id',
                'users.name',
                'users.email',
                'users.password',
                'memberships.tenant_id',
                'memberships.id as membership_id',
                'memberships.role',
                'memberships.status'
            ])
            ->where('users.id', '=', $userUuid)
            ->first();

        return $dbUser ? (array) $dbUser : null;
    }
}
