<?php

namespace Modules\Core\Application\Identity;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Core\Domain\Identity\User;

/**
 * Mengakhiri sesi login sebuah akun (PRD-000 §4.7: atur ulang sandi & nonaktifkan akun).
 *
 * - Baris sesi di tabel `sessions` (driver database, bawaan EduCore) dihapus.
 * - Token "ingat saya" diganti, sehingga cookie lama tidak bisa dipakai masuk lagi.
 *
 * Driver sesi lain tidak bisa disapu dari sini; untuk itu middleware EnsureAccountUsable tetap
 * mengeluarkan akun nonaktif di request berikutnya.
 */
final class UserSessions
{
    public function end(User $user, ?string $exceptSessionId = null): void
    {
        DB::table((string) config('session.table', 'sessions'))
            ->where('user_id', $user->id)
            ->when($exceptSessionId !== null, fn ($query) => $query->where('id', '!=', $exceptSessionId))
            ->delete();

        $user->setRememberToken(Str::random(60));
        $user->save();
    }
}
