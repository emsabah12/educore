<?php

namespace Modules\Core\Application\Identity;

use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Modules\Core\Domain\Identity\User;
use Modules\Core\Domain\Identity\UserStatus;

/**
 * Verifikasi login global: email ATAU username + password (PRD-000 §6, [LAMA] PRD-002).
 *
 * Mengembalikan null untuk SEMUA kegagalan (akun tidak ada, password salah,
 * akun nonaktif) agar pesan error tidak membocorkan keberadaan akun.
 */
final class AuthenticateUser
{
    public function handle(string $identifier, string $password): ?User
    {
        $identifier = Str::lower(trim($identifier));

        if ($identifier === '' || $password === '') {
            return null;
        }

        // Username tidak boleh mengandung "@" (lihat CHECK users_username_format_check),
        // sehingga "@" cukup untuk membedakan email dan username.
        $column = str_contains($identifier, '@') ? 'email' : 'username';

        $user = User::query()->where($column, $identifier)->first();

        if ($user === null || ! Hash::check($password, $user->password)) {
            return null;
        }

        if ($user->status !== UserStatus::Active) {
            return null;
        }

        return $user;
    }
}
