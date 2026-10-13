<?php

namespace Modules\Core\Application\Audit;

use Illuminate\Auth\Events\Failed;
use Illuminate\Auth\Events\Login;
use Illuminate\Auth\Events\Logout;
use Modules\Core\Domain\Identity\User;

/**
 * Mencatat masuk, gagal masuk, dan keluar (OD-15). Kejadian ini terjadi di luar yayasan,
 * jadi tenant_id kosong; subjeknya akun yang bersangkutan (bila dikenali).
 *
 * Kata sandi yang dicoba TIDAK pernah dicatat. Isian email/username hanya disimpan bila cocok
 * dengan akun yang ada; isian yang tidak dikenal (bisa saja kata sandi yang salah ketik di
 * kolom login) tidak disimpan.
 */
final class AuthAuditSubscriber
{
    private const LOGIN_MAX = 100;

    public function __construct(
        private readonly AuditLogger $audit,
    ) {}

    public function handleLogin(Login $event): void
    {
        if ($event->user instanceof User) {
            $this->audit->record(AuditAction::LOGIN, AuditLogger::SUBJECT_USER, $event->user->id, actor: $event->user, global: true);
        }
    }

    public function handleFailed(Failed $event): void
    {
        $attempted = $event->credentials['login'] ?? $event->credentials['email'] ?? null;
        $login = is_string($attempted) ? mb_substr(mb_strtolower(trim($attempted)), 0, self::LOGIN_MAX) : null;

        // Bila akunnya ada, jadikan subjek agar admin yayasannya bisa melihat percobaan ini.
        $user = $event->user instanceof User ? $event->user : $this->findUser($login);

        $this->audit->record(
            AuditAction::LOGIN_FAILED,
            $user === null ? null : AuditLogger::SUBJECT_USER,
            $user?->id,
            $user === null ? ['akun_dikenal' => false] : ['login' => $login],
            global: true,
        );
    }

    public function handleLogout(Logout $event): void
    {
        if ($event->user instanceof User) {
            $this->audit->record(AuditAction::LOGOUT, AuditLogger::SUBJECT_USER, $event->user->id, actor: $event->user, global: true);
        }
    }

    /**
     * @return array<class-string, string>
     */
    public function subscribe(): array
    {
        return [
            Login::class => 'handleLogin',
            Failed::class => 'handleFailed',
            Logout::class => 'handleLogout',
        ];
    }

    private function findUser(?string $login): ?User
    {
        if ($login === null || $login === '') {
            return null;
        }

        return User::query()
            ->where('email', $login)
            ->orWhere('username', $login)
            ->first();
    }
}
