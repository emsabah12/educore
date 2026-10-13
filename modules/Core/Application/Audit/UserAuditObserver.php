<?php

namespace Modules\Core\Application\Audit;

use Illuminate\Support\Facades\Auth;
use Modules\Core\Domain\Identity\User;

/**
 * Mencatat perubahan keamanan akun (OD-15): kata sandi, email, dan verifikasi dua langkah.
 *
 * Dipasang sebagai observer model User, sehingga semua jalur ikut tercatat
 * (halaman keamanan, lupa kata sandi, profil, Fortify 2FA) tanpa mengubah tiap controller.
 * Isi kata sandi & rahasia 2FA tidak pernah dicatat, hanya fakta bahwa ia berubah.
 */
final class UserAuditObserver
{
    public function __construct(
        private readonly AuditLogger $audit,
    ) {}

    public function updated(User $user): void
    {
        // Sandi sementara dari admin dicatat sendiri oleh ManageLoginAccount (PASSWORD_RESET),
        // jadi di sini hanya penggantian oleh pemilik akun (flag "wajib ganti" sudah mati).
        if ($user->wasChanged('password') && ! $user->must_change_password) {
            $this->record(AuditAction::PASSWORD_CHANGED, $user);
        }

        if ($user->wasChanged('email')) {
            $this->record(AuditAction::EMAIL_CHANGED, $user, ['email' => [$user->getOriginal('email'), $user->email]]);
        }

        if ($user->wasChanged('two_factor_confirmed_at') && $user->two_factor_confirmed_at !== null) {
            $this->record(AuditAction::TWO_FACTOR_ENABLED, $user);
        }

        if ($user->wasChanged('two_factor_secret') && $user->two_factor_secret === null && $user->getOriginal('two_factor_secret') !== null) {
            $this->record(AuditAction::TWO_FACTOR_DISABLED, $user);
        }
    }

    /**
     * @param  array<string, mixed>  $changes
     */
    private function record(string $action, User $user, array $changes = []): void
    {
        $current = Auth::user();

        $this->audit->record(
            $action,
            AuditLogger::SUBJECT_USER,
            $user->id,
            $changes,
            actor: $current instanceof User ? $current : $user,
            global: true,
        );
    }
}
