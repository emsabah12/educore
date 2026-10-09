<?php

namespace Modules\Core\Application\Identity;

use Illuminate\Support\Facades\DB;
use Modules\Core\Domain\Identity\User;
use Modules\Core\Domain\Person\Person;

/**
 * Pengguna mengubah profilnya sendiri: nama (milik Person) dan email (milik User).
 * Keduanya disimpan dalam satu transaksi (PRD-000 §5, [LAMA] §14).
 */
final class UpdateUserProfile
{
    public function handle(User $user, string $name, string $email): void
    {
        DB::transaction(function () use ($user, $name, $email): void {
            $person = Person::query()->findOrFail($user->person_id);
            $person->name = trim($name);
            $person->save();

            $user->email = $email;
            $user->save();
        });
    }
}
