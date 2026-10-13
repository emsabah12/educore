<?php

namespace Modules\Core\Application\Identity;

use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Core\Application\Audit\AuditAction;
use Modules\Core\Application\Audit\AuditLogger;
use Modules\Core\Domain\Identity\Exceptions\AccountException;
use Modules\Core\Domain\Identity\User;
use Modules\Core\Domain\Identity\UserStatus;
use Modules\Core\Domain\Tenancy\Membership;
use Modules\Core\Domain\Tenancy\MembershipStatus;

/**
 * Kelola akun login anggota yayasan oleh Admin Yayasan (PRD-000 §4.7 F5a, OD-20 s.d. OD-22):
 * buat akun, atur ulang kata sandi, nonaktifkan, aktifkan.
 *
 * Hak akses (core.memberships.manage di tingkat Yayasan) dicek di controller. Service ini menjaga
 * aturan datanya. Kata sandi sementara hanya dikembalikan lewat IssuedPassword, TIDAK pernah
 * masuk audit log.
 *
 * Akun (User) bersifat global. Karena itu akun Superadmin dan akun yang juga terdaftar di
 * yayasan lain tidak boleh diatur dari satu yayasan [ASUMSI, pengaman].
 */
final class ManageLoginAccount
{
    public const USERNAME_PATTERN = '/^[a-z0-9._-]{3,50}$/';

    public function __construct(
        private readonly AuditLogger $audit,
        private readonly UserSessions $sessions,
    ) {}

    /**
     * Buat akun login untuk anggota yang belum punya akun.
     *
     * @throws AccountException
     */
    public function provision(string $tenantId, string $membershipId, string $username, ?string $email): IssuedPassword
    {
        $username = Str::lower(trim($username));
        $email = $email === null || trim($email) === '' ? null : Str::lower(trim($email));

        if (preg_match(self::USERNAME_PATTERN, $username) !== 1) {
            throw AccountException::usernameInvalid();
        }

        if ($email !== null && (strlen($email) > 255 || filter_var($email, FILTER_VALIDATE_EMAIL) === false)) {
            throw AccountException::emailInvalid();
        }

        return DB::transaction(function () use ($tenantId, $membershipId, $username, $email): IssuedPassword {
            $membership = $this->lockMembership($tenantId, $membershipId);

            if ($membership->status !== MembershipStatus::Active) {
                throw AccountException::membershipInactive();
            }

            if (User::query()->where('person_id', $membership->person_id)->exists()) {
                throw AccountException::alreadyHasAccount();
            }

            if (User::query()->where('username', $username)->exists()) {
                throw AccountException::usernameTaken();
            }

            if ($email !== null && User::query()->where('email', $email)->exists()) {
                throw AccountException::emailTaken();
            }

            $password = TemporaryPassword::generate();

            $user = new User([
                'person_id' => $membership->person_id,
                'username' => $username,
                'email' => $email,
                'password' => $password,
            ]);
            // Tidak lewat mass assignment (lihat User::$fillable).
            $user->must_change_password = true;
            $user->save();

            $this->audit->record(
                AuditAction::ACCOUNT_CREATED,
                AuditLogger::SUBJECT_USER,
                $user->id,
                ['username' => $username, 'email' => $email, 'oleh_admin' => true],
                tenantId: $tenantId,
            );

            return new IssuedPassword($user->id, $username, $password);
        });
    }

    /**
     * Buat kata sandi sementara baru. Sesi login lain milik akun itu diakhiri; sesi admin yang
     * sedang dipakai ($currentSessionId) dibiarkan.
     *
     * @throws AccountException
     */
    public function resetPassword(string $tenantId, string $membershipId, ?string $currentSessionId = null): IssuedPassword
    {
        return DB::transaction(function () use ($tenantId, $membershipId, $currentSessionId): IssuedPassword {
            $user = $this->lockManageableUser($tenantId, $membershipId);
            $password = TemporaryPassword::generate();

            $user->password = $password;
            $user->must_change_password = true;
            $user->save();

            $this->sessions->end($user, $currentSessionId);

            $this->audit->record(
                AuditAction::PASSWORD_RESET,
                AuditLogger::SUBJECT_USER,
                $user->id,
                tenantId: $tenantId,
            );

            return new IssuedPassword($user->id, self::loginName($user), $password);
        });
    }

    /**
     * Akun nonaktif tidak bisa login; semua sesinya diakhiri. Data tidak dihapus (OD-06).
     *
     * @throws AccountException
     */
    public function deactivate(string $tenantId, string $membershipId, User $actor): void
    {
        DB::transaction(function () use ($tenantId, $membershipId, $actor): void {
            $user = $this->lockManageableUser($tenantId, $membershipId);

            if ($user->id === $actor->id) {
                throw AccountException::cannotDeactivateSelf();
            }

            if ($user->status === UserStatus::Inactive) {
                throw AccountException::alreadyInactive();
            }

            $user->status = UserStatus::Inactive;
            $user->save();

            $this->sessions->end($user);

            $this->audit->record(
                AuditAction::ACCOUNT_DEACTIVATED,
                AuditLogger::SUBJECT_USER,
                $user->id,
                tenantId: $tenantId,
            );
        });
    }

    /**
     * @throws AccountException
     */
    public function activate(string $tenantId, string $membershipId): void
    {
        DB::transaction(function () use ($tenantId, $membershipId): void {
            $user = $this->lockManageableUser($tenantId, $membershipId);

            if ($user->status === UserStatus::Active) {
                throw AccountException::alreadyActive();
            }

            $user->status = UserStatus::Active;
            $user->save();

            $this->audit->record(
                AuditAction::ACCOUNT_ACTIVATED,
                AuditLogger::SUBJECT_USER,
                $user->id,
                tenantId: $tenantId,
            );
        });
    }

    /**
     * Alasan akun ini tidak boleh diatur dari yayasan $tenantId, atau null bila boleh.
     */
    public function restriction(User $user, string $tenantId): ?AccountException
    {
        if ($user->is_superadmin) {
            return AccountException::superadmin();
        }

        $sharedElsewhere = Membership::query()
            ->where('person_id', $user->person_id)
            ->where('tenant_id', '!=', $tenantId)
            ->where('status', MembershipStatus::Active->value)
            ->exists();

        return $sharedElsewhere ? AccountException::sharedWithOtherTenant() : null;
    }

    /**
     * Nama yang dipakai untuk login: username, atau email bila akun lama tanpa username.
     */
    public static function loginName(User $user): string
    {
        return $user->username ?? (string) $user->email;
    }

    /**
     * Membership milik yayasan ini; selain itu 404 (keberadaannya tidak dibocorkan).
     */
    private function lockMembership(string $tenantId, string $membershipId): Membership
    {
        if (! Str::isUuid($membershipId)) {
            throw (new ModelNotFoundException)->setModel(Membership::class);
        }

        return Membership::query()
            ->where('tenant_id', $tenantId)
            ->whereKey($membershipId)
            ->lockForUpdate()
            ->firstOrFail();
    }

    /**
     * @throws AccountException
     */
    private function lockManageableUser(string $tenantId, string $membershipId): User
    {
        $membership = $this->lockMembership($tenantId, $membershipId);

        $user = User::query()->where('person_id', $membership->person_id)->lockForUpdate()->first();

        if (! $user instanceof User) {
            throw AccountException::noAccount();
        }

        $restriction = $this->restriction($user, $tenantId);

        if ($restriction !== null) {
            throw $restriction;
        }

        return $user;
    }
}
