<?php

namespace Modules\Academic\Application\Student;

use Closure;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Academic\Application\Audit\AcademicAuditAction;
use Modules\Academic\Domain\Student\Exceptions\StudentException;
use Modules\Academic\Domain\Student\GuardianRelation;
use Modules\Academic\Domain\Student\Student;
use Modules\Academic\Domain\Student\StudentGuardian;
use Modules\Core\Application\Audit\AuditLogger;
use Modules\Core\Application\Membership\GrantMembership;
use Modules\Core\Application\Person\PersonNik;
use Modules\Core\Domain\Person\Exceptions\NikTakenException;
use Modules\Core\Domain\Person\Gender;
use Modules\Core\Domain\Person\Person;
use Modules\Core\Domain\Tenancy\Membership;
use Modules\Core\Domain\Tenancy\MembershipStatus;
use Modules\HR\Contracts\EmployeeLookup;

/**
 * Orang tua/wali di detail siswa (PRD-002 §4.5, §6; AC-1c-b2):
 *
 * - search()       cari orang tua/wali yang SUDAH tercatat lewat nomor HP yang cocok persis;
 *                  hasilnya hanya nama, tanpa nama anak-anaknya [OWNER, 2026-10-18/19];
 * - addNew()       orang baru (atau dipakai ulang bila nama + HP sama, aturan B AC-1b);
 * - addExisting()  orang dari hasil pencarian (ID dari browser diperiksa ulang dengan HP-nya);
 * - update()       hubungan + data diri; orang yang juga pegawai/siswa hanya boleh diubah hubungannya;
 * - unlink()       lepas tautan (orangnya tetap ada); kontak utama yang dilepas wajib diganti bila
 *                  masih ada orang tua/wali lain;
 * - makePrimary()  ganti kontak utama.
 *
 * Hak akses (StudentManageAccess::canManageGuardians) dicek pemanggil. Membuat orang tua/wali tidak
 * membuat akun login dan tidak memberi hak akses apa pun. Audit tanpa nilai data pribadi.
 *
 * NIK orang tua/wali (opsional, OD-AC-02 revisi 2026-10-23): NIK yang sudah tercatat di yayasan ini
 * = orang yang sama (dipakai ulang, mis. kakak-adik), lebih pasti daripada nama + HP. NIK milik orang
 * di luar yayasan ditolak dengan pesan umum.
 */
final class ManageGuardians
{
    public const SEARCH_LIMIT = 5;

    public function __construct(
        private readonly GrantMembership $grantMembership,
        private readonly EmployeeLookup $employees,
        private readonly AuditLogger $audit,
        private readonly PersonNik $personNik,
        private readonly StudentNik $studentNik,
    ) {}

    // ── Pencarian ────────────────────────────────────────────────────────────

    /**
     * @return list<array{membership_id: string, name: string, already_linked: bool}>
     */
    public function search(string $tenantId, string $studentId, string $phone): array
    {
        $rows = $this->guardiansWithPhone($tenantId, $phone)
            ->orderBy('p.name')
            ->orderBy('m.id')
            ->limit(self::SEARCH_LIMIT)
            ->get(['m.id', 'p.name']);

        $linked = $this->linkedMembershipIds($studentId);
        $results = [];

        foreach ($rows as $row) {
            $results[] = [
                'membership_id' => (string) $row->id,
                'name' => (string) $row->name,
                'already_linked' => in_array((string) $row->id, $linked, true),
            ];
        }

        return $results;
    }

    // ── Tambah ───────────────────────────────────────────────────────────────

    /**
     * @return array{0: StudentGuardian, 1: bool} tautan baru, dan apakah orangnya sudah ada
     *
     * @throws StudentException
     */
    public function addNew(string $tenantId, string $studentId, GuardianData $guardian, bool $makePrimary): array
    {
        $this->assertGuardianInput($guardian->name, $guardian->phone, $guardian->nik);

        return $this->transaction(function () use ($tenantId, $studentId, $guardian, $makePrimary): array {
            $student = $this->lockStudent($tenantId, $studentId);
            [$membership, $reused] = $this->membershipFor($tenantId, $guardian);
            $link = $this->link($student, $membership, $guardian->relation, $makePrimary, $reused);

            return [$link, $reused];
        });
    }

    /**
     * @throws StudentException
     */
    public function addExisting(string $tenantId, string $studentId, string $membershipId, string $phone, GuardianRelation $relation, bool $makePrimary): StudentGuardian
    {
        return $this->transaction(function () use ($tenantId, $studentId, $membershipId, $phone, $relation, $makePrimary): StudentGuardian {
            $student = $this->lockStudent($tenantId, $studentId);

            // ID dari browser tidak dipercaya: wajib orang tua/wali yang tercatat dengan HP itu.
            $valid = Str::isUuid($membershipId)
                && $this->guardiansWithPhone($tenantId, $phone)->where('m.id', $membershipId)->exists();

            if (! $valid) {
                throw StudentException::make('membership_id', 'Pilihan orang tidak cocok dengan nomor HP yang dicari. Cari ulang lalu pilih dari hasilnya.');
            }

            $membership = Membership::query()->withoutGlobalScope('tenant')->whereKey($membershipId)->firstOrFail();

            return $this->link($student, $membership, $relation, $makePrimary, true);
        });
    }

    /**
     * Orang tua/wali untuk isian "orang baru". Urutan pencocokan:
     *
     * 1. NIK diisi dan sudah tercatat untuk anggota yayasan ini → orang itu (OD-AC-02 revisi);
     *    NIK milik orang di luar yayasan → ditolak (pesan umum, $nikField).
     * 2. NAMA dan NOMOR HP sama persis dengan anggota yayasan (kakak-adik berbagi orang tua)
     *    [usulan B AC-1b] — bila NIK diisi, orang lama yang sudah ber-NIK (pasti beda) dilewati,
     *    dan NIK melengkapi orang yang dipakai ulang.
     * 3. Selain itu orang baru.
     *
     * Pemanggil wajib di dalam transaksi.
     *
     * @return array{0: Membership, 1: bool} membership orang tua/wali, dan apakah orangnya sudah ada
     */
    public function membershipFor(string $tenantId, GuardianData $guardian, string $nikField = 'nik'): array
    {
        if ($guardian->nik !== null) {
            $ownerPersonId = null;
            $owner = $this->studentNik->memberOwning($tenantId, $guardian->nik, $ownerPersonId);

            if ($owner !== null) {
                return [$this->reuse($tenantId, $owner['person_id'], $guardian), true];
            }

            if ($ownerPersonId !== null) {
                throw StudentException::make($nikField, $this->studentNik->takenMessage($tenantId, $ownerPersonId));
            }
        }

        if ($guardian->phone !== null) {
            $personId = DB::table('persons as p')
                ->join('memberships as m', 'm.person_id', '=', 'p.id')
                ->where('m.tenant_id', $tenantId)
                ->where('p.phone', $guardian->phone)
                ->whereRaw("lower(regexp_replace(trim(p.name), '\\s+', ' ', 'g')) = ?", [StudentInput::comparableName($guardian->name)])
                ->when($guardian->nik !== null, fn (Builder $query) => $query->whereNotExists(fn (Builder $sub) => $sub->select(DB::raw(1))
                    ->from('person_identifiers as pi')
                    ->whereColumn('pi.person_id', 'p.id')
                    ->where('pi.type', PersonNik::TYPE)))
                ->orderBy('p.id')
                ->value('p.id');

            if (is_string($personId)) {
                $membership = $this->reuse($tenantId, $personId, $guardian);
                $this->assignNik($tenantId, $personId, $guardian->nik, $nikField);

                return [$membership, true];
            }
        }

        $person = Person::query()->create([
            'name' => $guardian->name,
            'gender' => $guardian->gender,
            'phone' => $guardian->phone,
        ]);

        $this->assignNik($tenantId, $person->id, $guardian->nik, $nikField);

        return [$this->grantMembership->handle($person->id, $tenantId), false];
    }

    /**
     * Pakai ulang orang yang sudah ada: data dirinya tidak ditimpa, hanya jenis kelamin yang kosong dilengkapi.
     */
    private function reuse(string $tenantId, string $personId, GuardianData $guardian): Membership
    {
        $person = Person::query()->whereKey($personId)->lockForUpdate()->firstOrFail();

        if ($person->gender === null && $guardian->gender !== null) {
            $person->gender = $guardian->gender;
            $person->save();
        }

        return $this->grantMembership->handle($person->id, $tenantId);
    }

    private function assignNik(string $tenantId, string $personId, ?string $nik, string $nikField): bool
    {
        if ($nik === null) {
            return false;
        }

        try {
            return $this->personNik->assign($personId, $nik);
        } catch (NikTakenException $exception) {
            throw StudentException::make($nikField, $this->studentNik->takenMessage($tenantId, $exception->ownerPersonId));
        }
    }

    // ── Ubah ─────────────────────────────────────────────────────────────────

    /**
     * Data diri ($name, $gender, $phone, $nik) diabaikan bila orang ini juga pegawai atau siswa: datanya
     * dikelola dari menu masing-masing [OWNER, 2026-10-19].
     *
     * $nik null = NIK tidak diubah (form tidak mengirim NIK lengkap; NIK yang tercatat tidak bisa
     * dikosongkan). Diisi = melengkapi atau memperbaiki.
     *
     * @return list<string> field yang berubah
     *
     * @throws StudentException
     */
    public function update(string $tenantId, string $studentId, string $linkId, GuardianRelation $relation, string $name, ?Gender $gender, ?string $phone, ?string $nik = null): array
    {
        return $this->transaction(function () use ($tenantId, $studentId, $linkId, $relation, $name, $gender, $phone, $nik): array {
            $this->lockStudent($tenantId, $studentId);
            $link = $this->lockLink($studentId, $linkId);
            $link->relation = $relation;
            $changed = array_keys($link->getDirty());

            if (! $this->isLocked($tenantId, $link->guardian_membership_id)) {
                $this->assertGuardianInput($name, $phone, $nik);

                $membership = Membership::query()->withoutGlobalScope('tenant')->whereKey($link->guardian_membership_id)->firstOrFail();
                $person = Person::query()->whereKey($membership->person_id)->lockForUpdate()->firstOrFail();
                $person->fill(['name' => $name, 'gender' => $gender, 'phone' => $phone]);
                $changed = array_merge($changed, array_keys($person->getDirty()));
                $person->save();

                if ($this->assignNik($tenantId, $person->id, $nik, 'nik')) {
                    $changed[] = 'nik';
                }
            }

            if ($changed === []) {
                return [];
            }

            $link->save();

            $this->audit->record(
                AcademicAuditAction::GUARDIAN_UPDATED,
                AcademicAuditAction::SUBJECT_STUDENT,
                $studentId,
                ['field_diubah' => $changed],
                tenantId: $tenantId,
            );

            return $changed;
        });
    }

    // ── Lepas & kontak utama ─────────────────────────────────────────────────

    /**
     * @throws StudentException
     */
    public function unlink(string $tenantId, string $studentId, string $linkId, ?string $newPrimaryLinkId): void
    {
        $this->transaction(function () use ($tenantId, $studentId, $linkId, $newPrimaryLinkId): bool {
            $this->lockStudent($tenantId, $studentId);
            $link = $this->lockLink($studentId, $linkId);
            $others = StudentGuardian::query()
                ->withoutGlobalScope('tenant')
                ->where('student_id', $studentId)
                ->whereKeyNot($link->id)
                ->lockForUpdate()
                ->get();

            $replacement = null;

            if ($link->is_primary_contact && $others->isNotEmpty()) {
                $replacement = $newPrimaryLinkId === null ? null : $others->firstWhere('id', $newPrimaryLinkId);

                if (! $replacement instanceof StudentGuardian) {
                    throw StudentException::make('new_primary_id', 'Orang ini kontak utama. Pilih orang tua/wali lain sebagai kontak utama pengganti.');
                }
            }

            $relation = $link->relation->value;
            $wasPrimary = $link->is_primary_contact;
            $link->delete();

            if ($replacement instanceof StudentGuardian) {
                $replacement->is_primary_contact = true;
                $replacement->save();
            }

            $this->audit->record(
                AcademicAuditAction::GUARDIAN_UNLINKED,
                AcademicAuditAction::SUBJECT_STUDENT,
                $studentId,
                [
                    'hubungan' => $relation,
                    'kontak_utama' => $wasPrimary,
                    'kontak_utama_diganti' => $replacement instanceof StudentGuardian,
                ],
                tenantId: $tenantId,
            );

            return true;
        });
    }

    /**
     * @throws StudentException
     */
    public function makePrimary(string $tenantId, string $studentId, string $linkId): void
    {
        $this->transaction(function () use ($tenantId, $studentId, $linkId): bool {
            $this->lockStudent($tenantId, $studentId);
            $link = $this->lockLink($studentId, $linkId);

            if ($link->is_primary_contact) {
                return false;
            }

            $this->clearPrimary($studentId);
            $link->is_primary_contact = true;
            $link->save();

            $this->audit->record(
                AcademicAuditAction::PRIMARY_CONTACT_CHANGED,
                AcademicAuditAction::SUBJECT_STUDENT,
                $studentId,
                ['hubungan' => $link->relation->value],
                tenantId: $tenantId,
            );

            return true;
        });
    }

    // ── Bantuan ───────────────────────────────────────────────────────────────

    /**
     * Orang yang juga punya profil pegawai atau siswa di yayasan ini: data dirinya tidak diubah dari
     * bagian orang tua/wali.
     *
     * @param  list<string>  $membershipIds
     * @return list<string>
     */
    public function lockedMembershipIds(string $tenantId, array $membershipIds): array
    {
        if ($membershipIds === []) {
            return [];
        }

        $students = [];

        foreach (DB::table('ac_students')->where('tenant_id', $tenantId)->whereIn('membership_id', $membershipIds)->pluck('membership_id') as $id) {
            $students[] = (string) $id;
        }

        return array_values(array_unique(array_merge($students, $this->employees->employeeMembershipIds($tenantId, $membershipIds))));
    }

    private function isLocked(string $tenantId, string $membershipId): bool
    {
        return $this->lockedMembershipIds($tenantId, [$membershipId]) !== [];
    }

    /**
     * Anggota aktif yayasan yang SUDAH tercatat sebagai orang tua/wali siswa mana pun, dengan HP itu.
     */
    private function guardiansWithPhone(string $tenantId, string $phone): Builder
    {
        return DB::table('memberships as m')
            ->join('persons as p', 'p.id', '=', 'm.person_id')
            ->where('m.tenant_id', $tenantId)
            ->where('m.status', MembershipStatus::Active->value)
            ->where('p.phone', $phone)
            ->whereExists(function (Builder $links): void {
                $links->select(DB::raw(1))
                    ->from('ac_student_guardians as g')
                    ->whereColumn('g.guardian_membership_id', 'm.id');
            });
    }

    private function link(Student $student, Membership $membership, GuardianRelation $relation, bool $makePrimary, bool $reused): StudentGuardian
    {
        if ($membership->id === $student->membership_id) {
            throw StudentException::make('name', 'Orang tua/wali tidak boleh siswa itu sendiri.');
        }

        $existing = StudentGuardian::query()
            ->withoutGlobalScope('tenant')
            ->where('student_id', $student->id)
            ->lockForUpdate()
            ->get();

        if ($existing->contains('guardian_membership_id', $membership->id)) {
            throw StudentException::make('form', 'Orang ini sudah tercatat sebagai orang tua/wali siswa ini.');
        }

        if ($existing->count() >= StudentInput::MAX_GUARDIANS) {
            throw StudentException::make('form', 'Paling banyak '.StudentInput::MAX_GUARDIANS.' orang tua/wali. Lepas salah satu dulu.');
        }

        // Orang tua/wali pertama otomatis menjadi kontak utama [ASUMSI].
        $primary = $makePrimary || ! $existing->contains('is_primary_contact', true);

        if ($primary) {
            $this->clearPrimary($student->id);
        }

        $link = new StudentGuardian;
        $link->fill([
            'tenant_id' => $student->tenant_id,
            'student_id' => $student->id,
            'guardian_membership_id' => $membership->id,
            'relation' => $relation,
            'is_primary_contact' => $primary,
        ]);
        $link->save();

        $this->audit->record(
            AcademicAuditAction::GUARDIAN_LINKED,
            AcademicAuditAction::SUBJECT_STUDENT,
            $student->id,
            [
                'hubungan' => $relation->value,
                'kontak_utama' => $primary,
                'orang_sudah_ada' => $reused,
            ],
            tenantId: $student->tenant_id,
        );

        return $link;
    }

    private function clearPrimary(string $studentId): void
    {
        StudentGuardian::query()
            ->withoutGlobalScope('tenant')
            ->where('student_id', $studentId)
            ->where('is_primary_contact', true)
            ->update(['is_primary_contact' => false, 'updated_at' => now()]);
    }

    /**
     * @return list<string>
     */
    private function linkedMembershipIds(string $studentId): array
    {
        $ids = [];

        foreach (DB::table('ac_student_guardians')->where('student_id', $studentId)->pluck('guardian_membership_id') as $id) {
            $ids[] = (string) $id;
        }

        return $ids;
    }

    private function lockStudent(string $tenantId, string $studentId): Student
    {
        $student = Str::isUuid($studentId)
            ? Student::query()->withoutGlobalScope('tenant')->where('tenant_id', $tenantId)->whereKey($studentId)->lockForUpdate()->first()
            : null;

        if (! $student instanceof Student) {
            throw StudentException::make('form', 'Siswa tidak ditemukan di yayasan ini.');
        }

        return $student;
    }

    private function lockLink(string $studentId, string $linkId): StudentGuardian
    {
        $link = Str::isUuid($linkId)
            ? StudentGuardian::query()->withoutGlobalScope('tenant')->where('student_id', $studentId)->whereKey($linkId)->lockForUpdate()->first()
            : null;

        if (! $link instanceof StudentGuardian) {
            throw StudentException::make('form', 'Orang tua/wali ini tidak tercatat untuk siswa ini.');
        }

        return $link;
    }

    private function assertGuardianInput(string $name, ?string $phone, ?string $nik = null): void
    {
        if ($nik !== null && ! PersonNik::isValid($nik)) {
            throw StudentException::nikInvalid();
        }

        if (trim($name) === '' || mb_strlen($name) > StudentInput::NAME_MAX_LENGTH) {
            throw StudentException::make('name', 'Nama orang tua/wali wajib diisi, maksimal 200 karakter.');
        }

        if ($phone !== null && preg_match(StudentInput::PHONE_PATTERN, $phone) !== 1) {
            throw StudentException::make('phone', 'Nomor HP hanya angka (boleh diawali +), 8–19 digit.');
        }
    }

    /**
     * @template T
     *
     * @param  Closure(): T  $callback
     * @return T
     */
    private function transaction(Closure $callback): mixed
    {
        try {
            return DB::transaction($callback);
        } catch (UniqueConstraintViolationException) {
            // Dua operator menyimpan bersamaan (orang yang sama / kontak utama ganda).
            throw StudentException::conflict();
        }
    }
}
