<?php

namespace Modules\Academic\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Modules\Academic\Application\Authorization\AcademicAccess;
use Modules\Academic\Application\Student\ManageEnrollments;
use Modules\Academic\Application\Student\StudentInput;
use Modules\Academic\Application\Student\StudentManageAccess;
use Modules\Academic\Application\Student\StudentProfileData;
use Modules\Academic\Application\Student\StudentVisibility;
use Modules\Academic\Application\Student\UpdateStudentProfile;
use Modules\Academic\Domain\Student\Enrollment;
use Modules\Academic\Domain\Student\EnrollmentEndReason;
use Modules\Academic\Domain\Student\EntryType;
use Modules\Academic\Domain\Student\Exceptions\StudentException;
use Modules\Core\Domain\Organization\Organization;
use Modules\Core\Domain\Person\Gender;
use Modules\Core\Domain\Tenancy\TenantContext;
use Modules\Core\Support\BusinessClock;

/**
 * Aksi di detail siswa (PRD-002 §6; AC-1c-b1): ubah data diri, daftarkan ke lembaga lain,
 * ubah pendaftaran, tutup pendaftaran.
 *
 * Urutan pemeriksaan: siswa tidak terlihat (yayasan lain, di luar cakupan, ID asal-asalan) → 404;
 * terlihat tetapi tanpa hak aksi → 403. Pendaftaran & lembaga tujuan dicek lewat Gate dengan
 * datanya (OwnedByOrganization), sehingga lembaga di luar cakupan → 404 (PRD-000 OD-13).
 * Aturan bisnis ada di service; pelanggarannya tampil sebagai pesan di isian form terkait.
 */
final class StudentActionController
{
    public function __construct(
        private readonly StudentVisibility $visibility,
        private readonly StudentManageAccess $access,
        private readonly TenantContext $tenantContext,
    ) {}

    public function updateProfile(Request $request, string $student, UpdateStudentProfile $updateProfile): RedirectResponse
    {
        $this->assertVisible($student);

        // Data diri milik orang (OD-AC-09): operator lembaga mana pun tempat siswa aktif, atau Admin Yayasan.
        if (! $this->access->canEditProfile($student)) {
            abort(403);
        }

        $request->merge([
            'phone' => StudentInput::phone($request->input('phone')),
            'nisn' => StudentInput::nisn($request->input('nisn')),
        ]);

        $request->validate([
            'name' => ['required', 'string', 'max:'.StudentInput::NAME_MAX_LENGTH],
            'gender' => ['required', Rule::enum(Gender::class)],
            'birth_place' => ['nullable', 'string', 'max:100'],
            'birth_date' => ['nullable', 'date_format:Y-m-d', 'before_or_equal:'.BusinessClock::todayString()],
            'phone' => ['nullable', 'string', 'regex:'.StudentInput::PHONE_PATTERN],
            'address' => ['nullable', 'string', 'max:500'],
            'nisn' => ['nullable', 'string', 'regex:'.StudentInput::NISN_PATTERN],
        ], [
            'name.required' => 'Nama lengkap wajib diisi.',
            'gender.required' => 'Jenis kelamin wajib dipilih.',
            'birth_date.before_or_equal' => 'Tanggal lahir tidak boleh di masa depan.',
            'phone.regex' => 'Nomor HP hanya angka (boleh diawali +), 8–19 digit.',
            'nisn.regex' => 'NISN harus 10 angka.',
        ], $this->attributes());

        /** @var Gender $gender */
        $gender = $request->enum('gender', Gender::class);

        $changed = $this->attempt(fn (): array => $updateProfile->handle($this->tenantId(), $student, new StudentProfileData(
            name: (string) StudentInput::name($request->input('name')),
            gender: $gender,
            birthPlace: StudentInput::text($request->input('birth_place')),
            birthDate: StudentInput::text($request->input('birth_date')),
            phone: StudentInput::text($request->input('phone')),
            address: StudentInput::text($request->input('address')),
            nisn: StudentInput::text($request->input('nisn')),
        )));

        return $this->done($changed === [] ? 'Tidak ada data yang berubah.' : 'Data diri siswa disimpan.');
    }

    public function enroll(Request $request, string $student, ManageEnrollments $enrollments): RedirectResponse
    {
        $this->assertVisible($student);

        if (! $this->access->managesAnywhere()) {
            abort(403);
        }

        $request->merge(['student_number' => StudentInput::studentNumber($request->input('student_number'))]);

        $request->validate([
            'organization_id' => ['required', 'uuid'],
            'student_number' => ['nullable', 'string', 'regex:'.StudentInput::STUDENT_NUMBER_PATTERN],
            'start_date' => ['required', 'date_format:Y-m-d', 'before_or_equal:'.BusinessClock::todayString()],
            'entry_type' => ['required', Rule::enum(EntryType::class)],
        ], $this->enrollmentMessages() + ['organization_id.required' => 'Pilih lembaga.'], $this->attributes());

        // Lembaga tujuan: yayasan lain / ID asal-asalan / di luar cakupan → 404; tanpa izin → 403.
        $organization = Organization::query()->find($request->string('organization_id')->value());

        if (! $organization instanceof Organization) {
            abort(404);
        }

        Gate::authorize(AcademicAccess::STUDENTS_MANAGE, $organization);

        /** @var EntryType $entryType */
        $entryType = $request->enum('entry_type', EntryType::class);

        $this->attempt(fn (): Enrollment => $enrollments->open(
            $this->tenantId(),
            $student,
            $organization->id,
            StudentInput::text($request->input('student_number')),
            $request->string('start_date')->value(),
            $entryType,
        ));

        return $this->done("Siswa didaftarkan di {$organization->name}.");
    }

    public function updateEnrollment(Request $request, string $student, string $enrollment, ManageEnrollments $enrollments): RedirectResponse
    {
        $target = $this->findEnrollment($student, $enrollment);

        $request->merge(['student_number' => StudentInput::studentNumber($request->input('student_number'))]);

        $request->validate([
            'student_number' => ['nullable', 'string', 'regex:'.StudentInput::STUDENT_NUMBER_PATTERN],
            'start_date' => ['required', 'date_format:Y-m-d', 'before_or_equal:'.BusinessClock::todayString()],
            'entry_type' => ['required', Rule::enum(EntryType::class)],
        ], $this->enrollmentMessages(), $this->attributes());

        /** @var EntryType $entryType */
        $entryType = $request->enum('entry_type', EntryType::class);

        $this->attempt(fn (): Enrollment => $enrollments->update(
            $this->tenantId(),
            $target->id,
            StudentInput::text($request->input('student_number')),
            $request->string('start_date')->value(),
            $entryType,
        ));

        return $this->done('Pendaftaran disimpan.');
    }

    public function closeEnrollment(Request $request, string $student, string $enrollment, ManageEnrollments $enrollments): RedirectResponse
    {
        $target = $this->findEnrollment($student, $enrollment);

        $request->validate([
            'end_date' => ['required', 'date_format:Y-m-d', 'before_or_equal:'.BusinessClock::todayString()],
            'end_reason' => ['required', Rule::enum(EnrollmentEndReason::class)],
            'end_note' => ['nullable', 'string', 'max:'.ManageEnrollments::NOTE_MAX_LENGTH],
        ], [
            'end_date.before_or_equal' => 'Tanggal keluar tidak boleh di masa depan.',
            'end_reason.required' => 'Pilih alasan.',
        ], $this->attributes());

        /** @var EnrollmentEndReason $reason */
        $reason = $request->enum('end_reason', EnrollmentEndReason::class);

        $classesClosed = $this->attempt(fn (): int => $enrollments->close(
            $this->tenantId(),
            $target->id,
            $request->string('end_date')->value(),
            $reason,
            StudentInput::text($request->input('end_note')),
        ));

        $message = 'Pendaftaran ditutup ('.$reason->label().').';

        if ($classesClosed > 0) {
            $message .= ' Siswa juga dikeluarkan dari kelasnya di lembaga ini.';
        }

        return $this->done($message);
    }

    // ── Bantuan ───────────────────────────────────────────────────────────────

    private function assertVisible(string $studentId): void
    {
        if (! $this->visibility->canSee($studentId, AcademicAccess::STUDENTS_VIEW)) {
            abort(404);
        }
    }

    /**
     * Pendaftaran milik siswa ini; lembaganya wajib di cakupan kelola (Gate: luar cakupan 404, tanpa izin 403).
     */
    private function findEnrollment(string $studentId, string $enrollmentId): Enrollment
    {
        $this->assertVisible($studentId);

        $enrollment = Str::isUuid($enrollmentId)
            ? Enrollment::query()->where('student_id', $studentId)->find($enrollmentId)
            : null;

        if (! $enrollment instanceof Enrollment) {
            abort(404);
        }

        Gate::authorize(AcademicAccess::STUDENTS_MANAGE, $enrollment);

        return $enrollment;
    }

    private function tenantId(): string
    {
        return $this->tenantContext->require()->tenantId;
    }

    /**
     * Jalankan service; pelanggaran aturan tampil sebagai pesan di isian form terkait.
     *
     * @template T
     *
     * @param  callable(): T  $action
     * @return T
     */
    private function attempt(callable $action): mixed
    {
        try {
            return $action();
        } catch (StudentException $exception) {
            throw ValidationException::withMessages([$exception->field => $exception->getMessage()]);
        }
    }

    private function done(string $message): RedirectResponse
    {
        Inertia::flash('toast', ['type' => 'success', 'message' => $message]);

        return back();
    }

    /**
     * @return array<string, string>
     */
    private function enrollmentMessages(): array
    {
        return [
            'start_date.before_or_equal' => 'Tanggal masuk tidak boleh di masa depan.',
            'student_number.regex' => 'Nomor induk hanya huruf, angka, titik, garis miring, dan tanda "-", maksimal 30 karakter.',
        ];
    }

    /**
     * @return array<string, string>
     */
    private function attributes(): array
    {
        return [
            'name' => 'nama lengkap',
            'gender' => 'jenis kelamin',
            'birth_place' => 'tempat lahir',
            'birth_date' => 'tanggal lahir',
            'phone' => 'nomor HP',
            'address' => 'alamat',
            'nisn' => 'NISN',
            'organization_id' => 'lembaga',
            'student_number' => 'nomor induk',
            'start_date' => 'tanggal masuk',
            'entry_type' => 'jenis masuk',
            'end_date' => 'tanggal keluar',
            'end_reason' => 'alasan',
            'end_note' => 'keterangan',
        ];
    }
}
