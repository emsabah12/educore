<?php

namespace Modules\Academic\Application\Student;

use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Modules\Academic\Application\Audit\AcademicAuditAction;
use Modules\Academic\Domain\Student\Enrollment;
use Modules\Academic\Domain\Student\Exceptions\StudentCheckFailed;
use Modules\Academic\Domain\Student\Exceptions\StudentException;
use Modules\Academic\Domain\Student\Student;
use Modules\Academic\Domain\Student\StudentGuardian;
use Modules\Core\Application\Audit\AuditLogger;
use Modules\Core\Application\Membership\GrantMembership;
use Modules\Core\Domain\Organization\Organization;
use Modules\Core\Domain\Person\Person;
use Modules\Core\Domain\Tenancy\Membership;
use Modules\Core\Support\BusinessClock;

/**
 * Menambah siswa (PRD-002 §4.3 invarian 1, §6; AC-1b):
 *
 *   orang baru  → Person + Membership + Siswa
 *   orang sama  → anggota yayasan yang sudah ada (dipilih dari calon orang ganda) → Siswa bila belum ada
 *   lalu        → pendaftaran di lembaga → orang tua/wali (0–4)
 *
 * Semuanya dalam SATU transaksi: bila satu langkah gagal, tidak ada yang tersimpan.
 * Tidak membuat akun login dan tidak memberi hak akses apa pun, termasuk untuk orang tua/wali.
 *
 * Hak akses ke lembaga tujuan (academic.students.manage) dicek pemanggil (controller) lewat Gate.
 */
final class CreateStudent
{
    public function __construct(
        private readonly GrantMembership $grantMembership,
        private readonly DuplicateCandidates $duplicateCandidates,
        private readonly ManageEnrollments $enrollments,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * @throws StudentException
     * @throws StudentCheckFailed
     */
    public function handle(NewStudentData $data): StudentCreated
    {
        $this->assertValidInput($data);

        try {
            return DB::transaction(fn (): StudentCreated => $this->create($data));
        } catch (UniqueConstraintViolationException $exception) {
            // Jaga-jaga bila dua operator menyimpan data yang sama pada saat bersamaan.
            throw $this->fromUniqueViolation($exception, $data);
        }
    }

    private function create(NewStudentData $data): StudentCreated
    {
        $organization = $this->enrollments->activeLembaga($data->tenantId, $data->organizationId);

        $newPerson = $data->existingMembershipId === null;

        if ($data->existingMembershipId === null) {
            [$membership, $person, $personalFields] = $this->createPerson($data, $organization);
        } else {
            [$membership, $person, $personalFields] = $this->useCandidate($data, $data->existingMembershipId);
        }

        [$student, $newStudent] = $this->studentFor($data, $membership);
        $enrollment = $this->enrollments->openFor($student, $organization, $data->studentNumber, $data->startDate, $data->entryType);
        [$linked, $reused] = $this->linkGuardians($data, $student, $membership);

        if ($newStudent) {
            // Data pribadi hanya dicatat NAMA field-nya, bukan nilainya (PRD-002 §4.5, §8).
            $this->audit->record(
                AcademicAuditAction::STUDENT_CREATED,
                AcademicAuditAction::SUBJECT_STUDENT,
                $student->id,
                [
                    'nama' => $person->name,
                    'orang_baru' => $newPerson,
                    'nisn_diisi' => $student->nisn !== null,
                    'data_pribadi_diisi' => $personalFields,
                ],
                organizationId: $organization->id,
                tenantId: $data->tenantId,
            );
        }

        $this->audit->record(
            AcademicAuditAction::ENROLLMENT_CREATED,
            AcademicAuditAction::SUBJECT_ENROLLMENT,
            $enrollment->id,
            [
                'nama' => $person->name,
                'lembaga' => $organization->code,
                'student_number' => $enrollment->student_number,
                'entry_type' => $enrollment->entry_type->value,
                'start_date' => $data->startDate,
                'siswa_baru' => $newStudent,
                'orang_tua_ditautkan' => $linked,
                'orang_tua_dipakai_ulang' => $reused,
            ],
            organizationId: $organization->id,
            tenantId: $data->tenantId,
        );

        return new StudentCreated(
            student: $student,
            enrollment: $enrollment,
            name: $person->name,
            organizationName: $organization->name,
            newPerson: $newPerson,
            guardiansLinked: $linked,
            guardiansReused: $reused,
        );
    }

    // ── Orang ─────────────────────────────────────────────────────────────────

    /**
     * Orang baru: cek kemungkinan orang ganda (kecuali operator sudah menyatakan orang berbeda),
     * lalu buat Person + Membership.
     *
     * @return array{0: Membership, 1: Person, 2: list<string>}
     */
    private function createPerson(NewStudentData $data, Organization $organization): array
    {
        $this->precheckNewPerson($data, $organization);

        $personal = $this->personalValues($data);

        $person = Person::query()->create(['name' => $data->name, 'gender' => $data->gender] + $personal);
        $membership = $this->grantMembership->handle($person->id, $data->tenantId);

        return [$membership, $person, array_keys($personal)];
    }

    /**
     * Sebelum membuat orang baru, kumpulkan SEMUA masalah sekaligus (bukan berhenti di masalah pertama),
     * agar operator langsung melihat isian mana yang salah [permintaan owner 2026-10-18]:
     *
     * - jenis kelamin kosong;
     * - NISN sudah dipakai siswa lain — bila pemiliknya termasuk calon nama sama, pesannya menunjuk ke
     *   kotak peringatan (kemungkinan orang yang sama), tanpa membuka data orang lain;
     * - nomor induk sudah dipakai siswa lain di lembaga ini;
     * - nama sama dengan anggota yayasan (kecuali operator sudah memilih "Orang berbeda").
     */
    private function precheckNewPerson(NewStudentData $data, Organization $organization): void
    {
        $errors = [];
        $candidates = $data->confirmNew ? [] : $this->duplicateCandidates->find($data->tenantId, $data->name);
        $candidateIds = array_column($candidates, 'membership_id');

        if ($data->gender === null) {
            $errors['gender'] = StudentException::genderRequired()->getMessage();
        }

        if ($data->nisn !== null) {
            $nisnOwner = Student::query()
                ->withoutGlobalScope('tenant')
                ->where('tenant_id', $data->tenantId)
                ->where('nisn', $data->nisn)
                ->value('membership_id');

            if (is_string($nisnOwner)) {
                $errors['nisn'] = in_array($nisnOwner, $candidateIds, true)
                    ? 'NISN ini sudah tercatat untuk salah satu orang di kotak peringatan (nama sama). Bila orangnya sama, pilih orang tersebut; bila berbeda, periksa lagi NISN-nya.'
                    : StudentException::duplicateNisn()->getMessage();
            }
        }

        if ($data->studentNumber !== null) {
            $numberTaken = Enrollment::query()
                ->withoutGlobalScope('tenant')
                ->where('tenant_id', $data->tenantId)
                ->where('organization_id', $organization->id)
                ->where('student_number', $data->studentNumber)
                ->exists();

            if ($numberTaken) {
                $errors['student_number'] = StudentException::duplicateStudentNumber($data->studentNumber)->getMessage();
            }
        }

        if ($errors !== [] || $candidates !== []) {
            throw new StudentCheckFailed($errors, $candidates, $data->name);
        }
    }

    /**
     * "Ini orang yang sama": calon wajib termasuk orang dengan nama yang sama (ID dari browser tidak
     * dipercaya begitu saja). Nama tidak diubah; data pribadi yang diisi hanya MELENGKAPI yang masih
     * kosong, sehingga data yang dicatat lembaga lain tidak tertimpa [usulan A, disetujui owner].
     *
     * @return array{0: Membership, 1: Person, 2: list<string>}
     */
    private function useCandidate(NewStudentData $data, string $membershipId): array
    {
        if (! in_array($membershipId, $this->duplicateCandidates->membershipIds($data->tenantId, $data->name), true)) {
            throw StudentException::candidateInvalid();
        }

        $membership = Membership::query()
            ->withoutGlobalScope('tenant')
            ->where('tenant_id', $data->tenantId)
            ->whereKey($membershipId)
            ->lockForUpdate()
            ->first();

        if (! $membership instanceof Membership) {
            throw StudentException::candidateInvalid();
        }

        if (! $membership->isActive()) {
            throw StudentException::membershipInactive();
        }

        $person = Person::query()->whereKey($membership->person_id)->lockForUpdate()->firstOrFail();

        if ($data->gender === null && $person->gender === null) {
            throw StudentException::genderRequired();
        }

        $filled = [];

        foreach ($this->personalValues($data) as $field => $value) {
            if ($person->getAttribute($field) === null) {
                $filled[$field] = $value;
            }
        }

        // Sampai di sini, jenis kelamin Person kosong berarti isian form pasti terisi (dicek di atas).
        if ($person->gender === null) {
            $person->gender = $data->gender;
        }

        $person->fill($filled);

        if ($person->isDirty()) {
            $person->save();
        }

        return [$membership, $person, array_keys($filled)];
    }

    /**
     * Data pribadi yang diisi saja.
     *
     * @return array<string, string>
     */
    private function personalValues(NewStudentData $data): array
    {
        $candidates = [
            'birth_place' => $data->birthPlace,
            'birth_date' => $data->birthDate,
            'phone' => $data->phone,
            'address' => $data->address,
        ];

        $values = [];

        foreach ($candidates as $field => $value) {
            if ($value !== null) {
                $values[$field] = $value;
            }
        }

        return $values;
    }

    // ── Siswa & pendaftaran ───────────────────────────────────────────────────

    /**
     * Profil siswa milik membership ini; dibuat bila belum ada. NISN yang diisi melengkapi
     * NISN kosong, tetapi tidak boleh berbeda dari NISN yang sudah tercatat.
     *
     * @return array{0: Student, 1: bool} siswa, dan apakah profilnya baru dibuat
     */
    private function studentFor(NewStudentData $data, Membership $membership): array
    {
        $student = Student::query()
            ->withoutGlobalScope('tenant')
            ->where('membership_id', $membership->id)
            ->lockForUpdate()
            ->first();

        if ($student instanceof Student) {
            if ($data->nisn !== null && $student->nisn !== null && $student->nisn !== $data->nisn) {
                throw StudentException::nisnMismatch();
            }

            if ($data->nisn !== null && $student->nisn === null) {
                $this->assertNisnFree($data->tenantId, $data->nisn);
                $student->nisn = $data->nisn;
                $student->save();
            }

            return [$student, false];
        }

        if ($data->nisn !== null) {
            $this->assertNisnFree($data->tenantId, $data->nisn);
        }

        $student = new Student;
        $student->tenant_id = $data->tenantId;
        $student->membership_id = $membership->id;
        $student->nisn = $data->nisn;
        $student->save();

        return [$student, true];
    }

    // ── Orang tua/wali ────────────────────────────────────────────────────────

    /**
     * Tautkan orang tua/wali. Orang tua dengan NAMA dan NOMOR HP yang sama persis dengan anggota
     * yayasan dipakai ulang (kakak-adik berbagi orang tua) [usulan B, disetujui owner]; selain itu
     * dibuat sebagai orang baru. Kontak utama hanya dipasang bila siswa belum punya kontak utama,
     * agar kontak yang dicatat lembaga lain tidak berubah diam-diam [ASUMSI].
     *
     * @return array{0: int, 1: int} jumlah yang ditautkan, dan berapa di antaranya orang yang sudah ada
     */
    private function linkGuardians(NewStudentData $data, Student $student, Membership $studentMembership): array
    {
        if ($data->guardians === []) {
            return [0, 0];
        }

        $existing = StudentGuardian::query()
            ->withoutGlobalScope('tenant')
            ->where('student_id', $student->id)
            ->lockForUpdate()
            ->get();

        $linkedIds = $existing->pluck('guardian_membership_id')->map(fn ($id): string => (string) $id)->all();
        $hasPrimary = $existing->contains('is_primary_contact', true);
        $seen = [];
        $linked = 0;
        $reused = 0;

        foreach ($data->guardians as $index => $guardian) {
            [$membership, $isReused] = $this->guardianMembership($data->tenantId, $guardian);

            if ($membership->id === $studentMembership->id) {
                throw StudentException::guardianIsStudent($index);
            }

            if (isset($seen[$membership->id])) {
                throw StudentException::guardianListedTwice($index);
            }

            $seen[$membership->id] = true;

            if ($isReused) {
                $reused++;
            }

            // Sudah tertaut (siswa lama yang didaftarkan ke lembaga lain): tidak dibuat ulang.
            if (in_array($membership->id, $linkedIds, true)) {
                continue;
            }

            if (count($linkedIds) >= StudentInput::MAX_GUARDIANS) {
                throw StudentException::tooManyGuardians(StudentInput::MAX_GUARDIANS);
            }

            $primary = ! $hasPrimary && $data->primaryGuardian === $index;

            $link = new StudentGuardian;
            $link->fill([
                'tenant_id' => $data->tenantId,
                'student_id' => $student->id,
                'guardian_membership_id' => $membership->id,
                'relation' => $guardian->relation,
                'is_primary_contact' => $primary,
            ]);
            $link->save();

            $linkedIds[] = $membership->id;
            $linked++;

            $this->audit->record(
                AcademicAuditAction::GUARDIAN_LINKED,
                AcademicAuditAction::SUBJECT_STUDENT,
                $student->id,
                [
                    'hubungan' => $guardian->relation->value,
                    'kontak_utama' => $primary,
                    'orang_sudah_ada' => $isReused,
                ],
                tenantId: $data->tenantId,
            );
        }

        return [$linked, $reused];
    }

    /**
     * @return array{0: Membership, 1: bool} membership orang tua/wali, dan apakah orangnya sudah ada
     */
    private function guardianMembership(string $tenantId, GuardianData $guardian): array
    {
        if ($guardian->phone !== null) {
            $personId = DB::table('persons as p')
                ->join('memberships as m', 'm.person_id', '=', 'p.id')
                ->where('m.tenant_id', $tenantId)
                ->where('p.phone', $guardian->phone)
                ->whereRaw("lower(regexp_replace(trim(p.name), '\\s+', ' ', 'g')) = ?", [StudentInput::comparableName($guardian->name)])
                ->orderBy('p.id')
                ->value('p.id');

            if (is_string($personId)) {
                $person = Person::query()->whereKey($personId)->lockForUpdate()->firstOrFail();

                if ($person->gender === null && $guardian->gender !== null) {
                    $person->gender = $guardian->gender;
                    $person->save();
                }

                return [$this->grantMembership->handle($person->id, $tenantId), true];
            }
        }

        $person = Person::query()->create([
            'name' => $guardian->name,
            'gender' => $guardian->gender,
            'phone' => $guardian->phone,
        ]);

        return [$this->grantMembership->handle($person->id, $tenantId), false];
    }

    // ── Rujukan & validasi ────────────────────────────────────────────────────

    private function assertNisnFree(string $tenantId, string $nisn): void
    {
        $taken = Student::query()
            ->withoutGlobalScope('tenant')
            ->where('tenant_id', $tenantId)
            ->where('nisn', $nisn)
            ->exists();

        if ($taken) {
            throw StudentException::duplicateNisn();
        }
    }

    /**
     * Penjaga di tingkat service (form sudah memvalidasi lebih dulu); database tetap lapisan terakhir.
     */
    private function assertValidInput(NewStudentData $data): void
    {
        if ($data->name === '' || mb_strlen($data->name) > StudentInput::NAME_MAX_LENGTH) {
            throw StudentException::nameRequired();
        }

        $today = BusinessClock::todayString();

        if (! StudentInput::isDate($data->startDate)) {
            throw StudentException::make('start_date', 'Tanggal masuk tidak valid.');
        }

        if ($data->startDate > $today) {
            throw StudentException::futureDate('start_date', 'Tanggal masuk');
        }

        if ($data->birthDate !== null && ! StudentInput::isDate($data->birthDate)) {
            throw StudentException::make('birth_date', 'Tanggal lahir tidak valid.');
        }

        if ($data->birthDate !== null && $data->birthDate > $today) {
            throw StudentException::futureDate('birth_date', 'Tanggal lahir');
        }

        if ($data->phone !== null && preg_match(StudentInput::PHONE_PATTERN, $data->phone) !== 1) {
            throw StudentException::make('phone', 'Nomor HP hanya angka (boleh diawali +), 8–19 digit.');
        }

        if ($data->nisn !== null && preg_match(StudentInput::NISN_PATTERN, $data->nisn) !== 1) {
            throw StudentException::make('nisn', 'NISN harus 10 angka.');
        }

        if ($data->studentNumber !== null && preg_match(StudentInput::STUDENT_NUMBER_PATTERN, $data->studentNumber) !== 1) {
            throw StudentException::make('student_number', 'Nomor induk hanya huruf, angka, titik, garis miring, dan tanda "-", maksimal 30 karakter.');
        }

        if (count($data->guardians) > StudentInput::MAX_GUARDIANS) {
            throw StudentException::tooManyGuardians(StudentInput::MAX_GUARDIANS);
        }

        foreach ($data->guardians as $index => $guardian) {
            if ($guardian->name === '' || mb_strlen($guardian->name) > StudentInput::NAME_MAX_LENGTH) {
                throw StudentException::make("guardians.{$index}.name", 'Nama orang tua/wali wajib diisi, maksimal 200 karakter.');
            }

            if ($guardian->phone !== null && preg_match(StudentInput::PHONE_PATTERN, $guardian->phone) !== 1) {
                throw StudentException::make("guardians.{$index}.phone", 'Nomor HP hanya angka (boleh diawali +), 8–19 digit.');
            }
        }

        if ($data->guardians !== [] && ($data->primaryGuardian === null || ! isset($data->guardians[$data->primaryGuardian]))) {
            throw StudentException::primaryGuardianInvalid();
        }
    }

    private function fromUniqueViolation(UniqueConstraintViolationException $exception, NewStudentData $data): StudentException
    {
        $message = $exception->getMessage();

        if (str_contains($message, 'nisn')) {
            return StudentException::duplicateNisn();
        }

        if (str_contains($message, 'ac_enrollments_open_number_unique') && $data->studentNumber !== null) {
            return StudentException::duplicateStudentNumber($data->studentNumber);
        }

        if (str_contains($message, 'ac_enrollments_one_open_unique')) {
            return StudentException::alreadyEnrolled();
        }

        return StudentException::conflict();
    }
}
