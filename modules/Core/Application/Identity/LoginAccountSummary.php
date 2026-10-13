<?php

namespace Modules\Core\Application\Identity;

use Illuminate\Support\Str;
use Modules\Core\Domain\Identity\User;
use Modules\Core\Domain\Identity\UserStatus;
use Modules\Core\Domain\Tenancy\Membership;

/**
 * Data bagian "Akses login" di detail pegawai (PRD-000 §4.7, PRD-001 §6).
 *
 * Milik Core; modul HR hanya memanggil dan menampilkannya (arah dependency HR → Core).
 * Hanya dipanggil untuk Admin Yayasan, jadi boleh memuat username & email akun.
 */
final class LoginAccountSummary
{
    public function __construct(
        private readonly ManageLoginAccount $manageLoginAccount,
    ) {}

    /**
     * @return array{
     *     membershipId: string,
     *     account: array{username: string|null, email: string|null, login: string, active: bool, mustChangePassword: bool}|null,
     *     restriction: string|null,
     *     isSelf: bool,
     * }|null null bila membership tidak ada di yayasan ini
     */
    public function forMembership(string $tenantId, string $membershipId, ?User $viewer): ?array
    {
        $membership = Str::isUuid($membershipId)
            ? Membership::query()->where('tenant_id', $tenantId)->whereKey($membershipId)->first()
            : null;

        if (! $membership instanceof Membership) {
            return null;
        }

        $user = User::query()->where('person_id', $membership->person_id)->first();

        if (! $user instanceof User) {
            return [
                'membershipId' => $membership->id,
                'account' => null,
                'restriction' => null,
                'isSelf' => false,
            ];
        }

        return [
            'membershipId' => $membership->id,
            'account' => [
                'username' => $user->username,
                'email' => $user->email,
                'login' => ManageLoginAccount::loginName($user),
                'active' => $user->status === UserStatus::Active,
                'mustChangePassword' => $user->must_change_password,
            ],
            'restriction' => $this->manageLoginAccount->restriction($user, $tenantId)?->getMessage(),
            'isSelf' => $viewer !== null && $viewer->id === $user->id,
        ];
    }
}
