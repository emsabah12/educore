<?php

namespace Modules\Academic\Application\Guardian;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Academic\Application\Student\ManageGuardians;
use Modules\Academic\Domain\Student\Exceptions\StudentException;
use Modules\Core\Application\Identity\IssuedPassword;
use Modules\Core\Application\Identity\ManageLoginAccount;
use Modules\Core\Domain\Identity\Exceptions\AccountException;
use Modules\Core\Domain\Identity\User;
use Modules\Core\Domain\Identity\UserStatus;
use Modules\Core\Domain\Tenancy\MembershipStatus;

/**
 * Akun login orang tua/wali (PRD-002 §21, AC-7a) — dibuat & diatur dari detail siswa oleh pemilik izin
 * kelola orang tua/wali siswa itu (Operator Akademik lembaganya, Admin Yayasan). Hak akses dicek pemanggil.
 *
 * Aturan [ASUMSI disetujui 2026-10-28]:
 * - username = nomor HP orang tua yang tercatat (tanpa "+"); HP wajib ada; sandi sementara tampil sekali,
 *   wajib diganti (Core ManageLoginAccount, OD-20);
 * - orang yang sudah punya akun (mis. juga pegawai) tidak dibuatkan akun baru — akunnya otomatis
 *   mendapat menu Anak saya;
 * - akun hanya boleh dibuat/diatur dari sini bila orangnya **murni orang tua/wali** di yayasan ini:
 *   bukan pegawai, bukan siswa, tanpa penugasan & role. Selain itu akunnya diatur Admin Yayasan
 *   (Kelola akses), agar operator tidak bisa mengatur ulang sandi pegawai/admin.
 *
 * @phpstan-type GuardianAccount array{status: 'NONE'|'ACTIVE'|'INACTIVE', login: string|null, must_change: bool, manageable: bool, reason: string|null}
 */
final class GuardianAccounts
{
    public function __construct(
        private readonly ManageLoginAccount $accounts,
        private readonly ManageGuardians $guardians,
    ) {}

    /**
     * Status akun tiap orang tua/wali seorang siswa: id tautan (ac_student_guardians) → akun.
     *
     * @return array<string, GuardianAccount>
     */
    public function forStudent(string $tenantId, string $studentId): array
    {
        $links = DB::table('ac_student_guardians')->where('tenant_id', $tenantId)->where('student_id', $studentId)->pluck('guardian_membership_id', 'id')->all();
        $membershipIds = [];

        foreach ($links as $membershipId) {
            $membershipIds[] = (string) $membershipId;
        }

        $accounts = $this->forMemberships($tenantId, array_values(array_unique($membershipIds)));
        $result = [];

        foreach ($links as $linkId => $membershipId) {
            $result[(string) $linkId] = $accounts[(string) $membershipId];
        }

        return $result;
    }

    /**
     * Status akun per membership orang tua/wali.
     *
     * @param  list<string>  $membershipIds
     * @return array<string, GuardianAccount> membership → akun
     */
    public function forMemberships(string $tenantId, array $membershipIds): array
    {
        if ($membershipIds === []) {
            return [];
        }

        $persons = DB::table('memberships')->where('tenant_id', $tenantId)->whereIn('id', $membershipIds)->pluck('person_id', 'id')->all();
        $users = User::query()->whereIn('person_id', array_values($persons))->get()->keyBy('person_id');
        $blocked = $this->blockedMemberships($tenantId, $membershipIds);
        $result = [];

        foreach ($membershipIds as $membershipId) {
            $user = $users->get((string) ($persons[$membershipId] ?? ''));
            $reason = $blocked[$membershipId] ?? null;

            if ($user instanceof User && $reason === null) {
                $reason = $this->accounts->restriction($user, $tenantId)?->getMessage();
            }

            $result[$membershipId] = [
                'status' => ! $user instanceof User ? 'NONE' : ($user->status === UserStatus::Active ? 'ACTIVE' : 'INACTIVE'),
                'login' => $user instanceof User ? ManageLoginAccount::loginName($user) : null,
                'must_change' => $user instanceof User && $user->must_change_password,
                'manageable' => $reason === null,
                'reason' => $reason,
            ];
        }

        return $result;
    }

    /**
     * @throws StudentException
     */
    public function provision(string $tenantId, string $studentId, string $linkId): IssuedPassword
    {
        [$membershipId, $phone] = $this->link($tenantId, $studentId, $linkId);
        $this->assertManageable($tenantId, $membershipId);

        if ($phone === null) {
            throw StudentException::make('form', 'Isi dulu nomor HP orang tua/wali ini; nomor HP dipakai sebagai username.');
        }

        $username = ltrim($phone, '+');

        // Data lama sebelum HP dibuat unik [OWNER, 2026-10-28]: HP yang sama dengan orang tua/wali lain
        // harus dibereskan dulu, agar username tidak menunjuk orang yang salah.
        $other = $this->guardians->guardiansByPhone($tenantId, $username)->where('m.id', '!=', $membershipId)->orderBy('p.name')->value('p.name');

        if (is_string($other)) {
            throw StudentException::make('form', "Nomor HP {$phone} juga tercatat untuk orang tua/wali lain ({$other}). Perbaiki salah satunya dulu (tombol Ubah), lalu buat akun.");
        }

        try {
            return $this->accounts->provision($tenantId, $membershipId, $username, null);
        } catch (AccountException $exception) {
            throw StudentException::make('form', match ($exception->getMessage()) {
                AccountException::usernameTaken()->getMessage() => "Nomor HP {$username} sudah dipakai sebagai username akun lain. Periksa nomor HP orang tua/wali ini.",
                AccountException::alreadyHasAccount()->getMessage() => 'Orang tua/wali ini sudah punya akun login.',
                default => $exception->getMessage(),
            });
        }
    }

    /**
     * @throws StudentException
     */
    public function resetPassword(string $tenantId, string $studentId, string $linkId, ?string $currentSessionId): IssuedPassword
    {
        [$membershipId] = $this->link($tenantId, $studentId, $linkId);
        $this->assertManageable($tenantId, $membershipId);

        return $this->core(fn (): IssuedPassword => $this->accounts->resetPassword($tenantId, $membershipId, $currentSessionId));
    }

    /**
     * @throws StudentException
     */
    public function setActive(string $tenantId, string $studentId, string $linkId, bool $active, User $actor): void
    {
        [$membershipId] = $this->link($tenantId, $studentId, $linkId);
        $this->assertManageable($tenantId, $membershipId);

        $this->core(function () use ($tenantId, $membershipId, $active, $actor): bool {
            if ($active) {
                $this->accounts->activate($tenantId, $membershipId);
            } else {
                $this->accounts->deactivate($tenantId, $membershipId, $actor);
            }

            return true;
        });
    }

    // ── Bantuan ───────────────────────────────────────────────────────────────

    /**
     * Tautan orang tua/wali milik siswa ini → [membership, HP].
     *
     * @return array{0: string, 1: string|null}
     *
     * @throws StudentException
     */
    private function link(string $tenantId, string $studentId, string $linkId): array
    {
        $row = Str::isUuid($linkId) && Str::isUuid($studentId)
            ? DB::table('ac_student_guardians as g')
                ->join('memberships as m', 'm.id', '=', 'g.guardian_membership_id')
                ->join('persons as p', 'p.id', '=', 'm.person_id')
                ->where('g.tenant_id', $tenantId)
                ->where('g.student_id', $studentId)
                ->where('g.id', $linkId)
                ->first(['g.guardian_membership_id', 'm.status', 'p.phone'])
            : null;

        if ($row === null) {
            throw StudentException::make('form', 'Orang tua/wali tidak ditemukan pada siswa ini.');
        }

        if ((string) $row->status !== MembershipStatus::Active->value) {
            throw StudentException::make('form', 'Keanggotaan orang tua/wali ini di yayasan sudah tidak aktif.');
        }

        return [(string) $row->guardian_membership_id, $row->phone === null ? null : (string) $row->phone];
    }

    /**
     * @throws StudentException
     */
    private function assertManageable(string $tenantId, string $membershipId): void
    {
        $reason = $this->blockedMemberships($tenantId, [$membershipId])[$membershipId] ?? null;

        if ($reason !== null) {
            throw StudentException::make('form', $reason);
        }
    }

    /**
     * Alasan akun orang ini tidak boleh diatur dari halaman siswa (bukan murni orang tua/wali).
     *
     * @param  list<string>  $membershipIds
     * @return array<string, string>
     */
    private function blockedMemberships(string $tenantId, array $membershipIds): array
    {
        $result = [];
        $message = 'Orang ini juga pegawai, siswa, atau punya peran lain di yayasan; akun loginnya diatur Admin Yayasan lewat Kelola akses.';

        foreach ($this->guardians->lockedMembershipIds($tenantId, $membershipIds) as $id) {
            $result[$id] = $message;
        }

        $withRoles = DB::table('membership_roles')->where('tenant_id', $tenantId)->whereIn('membership_id', $membershipIds)->pluck('membership_id')
            ->merge(DB::table('organizational_assignments')->where('tenant_id', $tenantId)->whereIn('membership_id', $membershipIds)->pluck('membership_id'));

        foreach ($withRoles as $id) {
            $result[(string) $id] = $message;
        }

        return $result;
    }

    /**
     * @template T
     *
     * @param  callable(): T  $action
     * @return T
     *
     * @throws StudentException
     */
    private function core(callable $action): mixed
    {
        try {
            return $action();
        } catch (AccountException $exception) {
            throw StudentException::make('form', $exception->getMessage());
        }
    }
}
