<?php

namespace Modules\Academic\Application\Student;

use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Academic\Application\Audit\AcademicAuditAction;
use Modules\Academic\Domain\Student\Enrollment;
use Modules\Academic\Domain\Student\EnrollmentEndReason;
use Modules\Academic\Domain\Student\EntryType;
use Modules\Academic\Domain\Student\Exceptions\StudentException;
use Modules\Academic\Domain\Student\Student;
use Modules\Core\Application\Audit\AuditLogger;
use Modules\Core\Domain\Organization\Organization;
use Modules\Core\Domain\Organization\OrganizationType;
use Modules\Core\Support\BusinessClock;

/**
 * Pendaftaran siswa di lembaga (PRD-002 §4.2, §4.3, §6; AC-1c-b1):
 *
 * - open()   daftarkan siswa yang sudah ada ke lembaga lain (atau daftar ulang setelah keluar);
 * - update() perbaiki salah input: nomor induk, tanggal masuk, jenis masuk (invarian 3);
 * - close()  tutup pendaftaran (lulus/pindah/…); keanggotaan kelas yang terbuka ikut ditutup.
 *            Pendaftaran di lembaga lain TIDAK berubah (invarian 2).
 *
 * openFor() & activeLembaga() juga dipakai CreateStudent agar aturan pendaftaran hanya ada di satu tempat.
 * Hak akses (academic.students.manage di lembaga pendaftaran) dicek pemanggil lewat Gate.
 */
final class ManageEnrollments
{
    public const NOTE_MAX_LENGTH = 500;

    public function __construct(
        private readonly AuditLogger $audit,
    ) {}

    // ── Daftarkan ke lembaga lain ────────────────────────────────────────────

    /**
     * @throws StudentException
     */
    public function open(string $tenantId, string $studentId, string $organizationId, ?string $studentNumber, string $startDate, EntryType $entryType): Enrollment
    {
        $this->assertNumberFormat($studentNumber);
        $this->assertPastDate($startDate, 'start_date', 'Tanggal masuk');

        return $this->guardUnique($studentNumber, function () use ($tenantId, $studentId, $organizationId, $studentNumber, $startDate, $entryType): Enrollment {
            $student = $this->lockStudent($tenantId, $studentId);
            $organization = $this->activeLembaga($tenantId, $organizationId);
            $enrollment = $this->openFor($student, $organization, $studentNumber, $startDate, $entryType);

            $this->audit->record(
                AcademicAuditAction::ENROLLMENT_CREATED,
                AcademicAuditAction::SUBJECT_ENROLLMENT,
                $enrollment->id,
                [
                    'nama' => $this->studentName($student),
                    'lembaga' => $organization->code,
                    'student_number' => $enrollment->student_number,
                    'entry_type' => $entryType->value,
                    'start_date' => $startDate,
                    'siswa_baru' => false,
                ],
                organizationId: $organization->id,
                tenantId: $tenantId,
            );

            return $enrollment;
        });
    }

    /**
     * Buka pendaftaran untuk siswa di lembaga. Pemanggil wajib di dalam transaksi.
     *
     * @throws StudentException
     */
    public function openFor(Student $student, Organization $organization, ?string $studentNumber, string $startDate, EntryType $entryType): Enrollment
    {
        $alreadyOpen = Enrollment::query()
            ->withoutGlobalScope('tenant')
            ->where('tenant_id', $student->tenant_id)
            ->where('organization_id', $organization->id)
            ->where('student_id', $student->id)
            ->whereNull('end_date')
            ->exists();

        if ($alreadyOpen) {
            throw StudentException::alreadyEnrolled();
        }

        $this->assertNumberFree($student->tenant_id, $organization->id, $student->id, $studentNumber);

        $enrollment = new Enrollment;
        $enrollment->fill([
            'tenant_id' => $student->tenant_id,
            'student_id' => $student->id,
            'organization_id' => $organization->id,
            'student_number' => $studentNumber,
            'entry_type' => $entryType,
            'start_date' => $startDate,
        ]);
        $enrollment->save();

        return $enrollment;
    }

    // ── Ubah pendaftaran ─────────────────────────────────────────────────────

    /**
     * Berlaku untuk pendaftaran berjalan maupun yang sudah ditutup (memperbaiki riwayat).
     *
     * @throws StudentException
     */
    public function update(string $tenantId, string $enrollmentId, ?string $studentNumber, string $startDate, EntryType $entryType): Enrollment
    {
        $this->assertNumberFormat($studentNumber);
        $this->assertPastDate($startDate, 'start_date', 'Tanggal masuk');

        return $this->guardUnique($studentNumber, function () use ($tenantId, $enrollmentId, $studentNumber, $startDate, $entryType): Enrollment {
            $enrollment = $this->lockEnrollment($tenantId, $enrollmentId);

            if ($enrollment->end_date !== null && $startDate > $enrollment->end_date->toDateString()) {
                throw StudentException::make('start_date', 'Tanggal masuk tidak boleh sesudah tanggal keluar ('.$enrollment->end_date->toDateString().').');
            }

            // Tanggal masuk tidak boleh sesudah siswa masuk kelas di lembaga ini.
            $firstClassDay = DB::table('ac_class_members')->where('enrollment_id', $enrollment->id)->min('start_date');

            if ($firstClassDay !== null && $startDate > substr((string) $firstClassDay, 0, 10)) {
                throw StudentException::make('start_date', 'Tanggal masuk tidak boleh sesudah siswa masuk kelas pertamanya ('.substr((string) $firstClassDay, 0, 10).').');
            }

            $this->assertNumberFree($tenantId, $enrollment->organization_id, $enrollment->student_id, $studentNumber);

            $enrollment->student_number = $studentNumber;
            $enrollment->start_date = CarbonImmutable::parse($startDate);
            $enrollment->entry_type = $entryType;

            $changed = array_keys($enrollment->getDirty());

            if ($changed === []) {
                return $enrollment;
            }

            $enrollment->save();

            $this->audit->record(
                AcademicAuditAction::ENROLLMENT_UPDATED,
                AcademicAuditAction::SUBJECT_ENROLLMENT,
                $enrollment->id,
                [
                    'nama' => $this->studentName($this->lockStudent($tenantId, $enrollment->student_id)),
                    'lembaga' => $this->organizationCode($enrollment->organization_id),
                    'field_diubah' => $changed,
                ],
                organizationId: $enrollment->organization_id,
                tenantId: $tenantId,
            );

            return $enrollment;
        });
    }

    // ── Tutup pendaftaran ────────────────────────────────────────────────────

    /**
     * @return int jumlah keanggotaan kelas yang ikut ditutup
     *
     * @throws StudentException
     */
    public function close(string $tenantId, string $enrollmentId, string $endDate, EnrollmentEndReason $reason, ?string $note): int
    {
        $this->assertPastDate($endDate, 'end_date', 'Tanggal keluar');

        if ($reason === EnrollmentEndReason::Other && $note === null) {
            throw StudentException::make('end_note', 'Tulis keterangan bila alasannya "Lainnya".');
        }

        if ($note !== null && mb_strlen($note) > self::NOTE_MAX_LENGTH) {
            throw StudentException::make('end_note', 'Keterangan maksimal '.self::NOTE_MAX_LENGTH.' karakter.');
        }

        return DB::transaction(function () use ($tenantId, $enrollmentId, $endDate, $reason, $note): int {
            $enrollment = $this->lockEnrollment($tenantId, $enrollmentId);

            if ($enrollment->end_date !== null) {
                throw StudentException::make('end_date', 'Pendaftaran ini sudah ditutup.');
            }

            if ($endDate < $enrollment->start_date->toDateString()) {
                throw StudentException::make('end_date', 'Tanggal keluar tidak boleh sebelum tanggal masuk ('.$enrollment->start_date->toDateString().').');
            }

            $openClasses = DB::table('ac_class_members')
                ->where('enrollment_id', $enrollment->id)
                ->whereNull('end_date');
            $latestClassDay = (clone $openClasses)->max('start_date');

            if ($latestClassDay !== null && $endDate < substr((string) $latestClassDay, 0, 10)) {
                throw StudentException::make('end_date', 'Tanggal keluar tidak boleh sebelum siswa masuk kelasnya sekarang ('.substr((string) $latestClassDay, 0, 10).').');
            }

            // Kelas di lembaga ini ikut ditutup di tanggal yang sama (PRD-002 §4.2).
            $classesClosed = (clone $openClasses)->update(['end_date' => $endDate, 'updated_at' => now()]);

            $enrollment->end_date = CarbonImmutable::parse($endDate);
            $enrollment->end_reason = $reason;
            $enrollment->end_note = $note;
            $enrollment->save();

            // Catatan bisa memuat hal pribadi: audit hanya mencatat ada/tidaknya (PRD-002 §4.5).
            $this->audit->record(
                AcademicAuditAction::ENROLLMENT_CLOSED,
                AcademicAuditAction::SUBJECT_ENROLLMENT,
                $enrollment->id,
                [
                    'nama' => $this->studentName($this->lockStudent($tenantId, $enrollment->student_id)),
                    'lembaga' => $this->organizationCode($enrollment->organization_id),
                    'end_date' => $endDate,
                    'end_reason' => $reason->value,
                    'catatan_diisi' => $note !== null,
                    'kelas_ditutup' => $classesClosed,
                ],
                organizationId: $enrollment->organization_id,
                tenantId: $tenantId,
            );

            return $classesClosed;
        });
    }

    // ── Rujukan & validasi bersama ───────────────────────────────────────────

    /**
     * @throws StudentException
     */
    public function activeLembaga(string $tenantId, string $id): Organization
    {
        $organization = Str::isUuid($id)
            ? Organization::query()->withoutGlobalScope('tenant')->where('tenant_id', $tenantId)->whereKey($id)->first()
            : null;

        if (! $organization instanceof Organization) {
            throw StudentException::organizationNotFound();
        }

        if ($organization->type !== OrganizationType::Lembaga) {
            throw StudentException::notALembaga();
        }

        if (! $organization->isActive()) {
            throw StudentException::organizationInactive();
        }

        return $organization;
    }

    /**
     * Nomor induk tidak boleh pernah dipakai siswa LAIN di lembaga ini (PRD-002 §4.2).
     */
    private function assertNumberFree(string $tenantId, string $organizationId, string $studentId, ?string $studentNumber): void
    {
        if ($studentNumber === null) {
            return;
        }

        $taken = Enrollment::query()
            ->withoutGlobalScope('tenant')
            ->where('tenant_id', $tenantId)
            ->where('organization_id', $organizationId)
            ->where('student_number', $studentNumber)
            ->where('student_id', '<>', $studentId)
            ->exists();

        if ($taken) {
            throw StudentException::duplicateStudentNumber($studentNumber);
        }
    }

    private function assertNumberFormat(?string $studentNumber): void
    {
        if ($studentNumber !== null && preg_match(StudentInput::STUDENT_NUMBER_PATTERN, $studentNumber) !== 1) {
            throw StudentException::make('student_number', 'Nomor induk hanya huruf, angka, titik, garis miring, dan tanda "-", maksimal 30 karakter.');
        }
    }

    private function assertPastDate(string $value, string $field, string $label): void
    {
        if (! StudentInput::isDate($value)) {
            throw StudentException::make($field, "{$label} tidak valid.");
        }

        if ($value > BusinessClock::todayString()) {
            throw StudentException::futureDate($field, $label);
        }
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

    private function lockEnrollment(string $tenantId, string $enrollmentId): Enrollment
    {
        $enrollment = Str::isUuid($enrollmentId)
            ? Enrollment::query()->withoutGlobalScope('tenant')->where('tenant_id', $tenantId)->whereKey($enrollmentId)->lockForUpdate()->first()
            : null;

        if (! $enrollment instanceof Enrollment) {
            throw StudentException::make('form', 'Pendaftaran tidak ditemukan di yayasan ini.');
        }

        return $enrollment;
    }

    private function studentName(Student $student): string
    {
        return (string) DB::table('memberships as m')
            ->join('persons as p', 'p.id', '=', 'm.person_id')
            ->where('m.id', $student->membership_id)
            ->value('p.name');
    }

    private function organizationCode(string $organizationId): string
    {
        return (string) DB::table('organizations')->where('id', $organizationId)->value('code');
    }

    /**
     * Jalankan dalam transaksi; bentrok index unik (dua operator menyimpan bersamaan) → pesan ramah.
     *
     * @template T
     *
     * @param  Closure(): T  $callback
     * @return T
     */
    private function guardUnique(?string $studentNumber, Closure $callback): mixed
    {
        try {
            return DB::transaction($callback);
        } catch (UniqueConstraintViolationException $exception) {
            $message = $exception->getMessage();

            if (str_contains($message, 'ac_enrollments_open_number_unique') && $studentNumber !== null) {
                throw StudentException::duplicateStudentNumber($studentNumber);
            }

            if (str_contains($message, 'ac_enrollments_one_open_unique')) {
                throw StudentException::alreadyEnrolled();
            }

            throw StudentException::conflict();
        }
    }
}
