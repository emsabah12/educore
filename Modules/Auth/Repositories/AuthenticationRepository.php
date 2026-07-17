<?php

declare(strict_types=1);

namespace Modules\Auth\Repositories;

use Modules\Core\Contracts\Auth\AuthenticationRepositoryInterface;
use Illuminate\Support\Facades\DB;

final class AuthenticationRepository implements AuthenticationRepositoryInterface
{
    private string $table = 'users';
    private string $membershipTable = 'memberships';

    /**
     * Mencari user berdasarkan email global dan memastikan memiliki membership aktif di tenant tertentu.
     * 
     * {@inheritdoc}
     */
    public function findByEmailForTenant(string $email, string $tenantUuid): ?array
    {
        // SQL Inner Join untuk memverifikasi Akun Global sekaligus status Keanggotaan di Lembaga target
        $user = DB::table($this->table)
            ->join($this->membershipTable, "{$this->table}.id", '=', "{$this->membershipTable}.user_id")
            ->select([
                "{$this->table}.id",
                "{$this->table}.name",
                "{$this->table}.email",
                "{$this->table}.password",
                "{$this->table}.status as user_status",
                "{$this->membershipTable}.id as membership_id",
                "{$this->membershipTable}.tenant_id",
                "{$this->membershipTable}.role",
                "{$this->membershipTable}.status as membership_status"
            ])
            ->where("{$this->table}.email", '=', strtolower($email))
            ->where("{$this->table}.status", '=', 'ACTIVE')
            ->where("{$this->membershipTable}.tenant_id", '=', $tenantUuid)
            ->where("{$this->membershipTable}.status", '=', 'ACTIVE')
            ->first();

        // Mengembalikan array jika ditemukan, null jika tidak ada relasi valid
        return $user ? (array) $user : null;
    }

    /**
     * Mengambil data profil user global berdasarkan ID uniknya.
     * 
     * {@inheritdoc}
     */
    public function findByUserUuid(string $userUuid): ?array
    {
        $user = DB::table($this->table)
            ->where('id', '=', $userUuid)
            ->where('status', '=', 'ACTIVE')
            ->first();

        return $user ? (array) $user : null;
    }
}
