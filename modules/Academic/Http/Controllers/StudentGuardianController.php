<?php

namespace Modules\Academic\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Modules\Academic\Application\Authorization\AcademicAccess;
use Modules\Academic\Application\Student\GuardianData;
use Modules\Academic\Application\Student\ManageGuardians;
use Modules\Academic\Application\Student\StudentInput;
use Modules\Academic\Application\Student\StudentManageAccess;
use Modules\Academic\Application\Student\StudentVisibility;
use Modules\Academic\Domain\Student\Exceptions\StudentException;
use Modules\Academic\Domain\Student\GuardianRelation;
use Modules\Academic\Domain\Student\StudentGuardian;
use Modules\Core\Application\Person\PersonNik;
use Modules\Core\Domain\Person\Gender;
use Modules\Core\Domain\Tenancy\TenantContext;

/**
 * Orang tua/wali di detail siswa (PRD-002 §4.5, §6; AC-1c-b2): cari lewat HP, tambah, ubah, lepas,
 * ganti kontak utama.
 *
 * Siswa tidak terlihat → 404; terlihat tetapi tidak boleh mengelola orang tua/wali → 403
 * (sama dengan ubah data diri, OD-AC-09). Aturan bisnis ada di ManageGuardians.
 */
final class StudentGuardianController
{
    public function __construct(
        private readonly StudentVisibility $visibility,
        private readonly StudentManageAccess $access,
        private readonly TenantContext $tenantContext,
    ) {}

    /**
     * Pencarian orang tua/wali yang sudah tercatat lewat nomor HP yang cocok persis (JSON).
     * Hanya nama, tanpa nama anak-anaknya [OWNER, 2026-10-19]. Dibatasi throttle di route.
     */
    public function search(Request $request, string $student, ManageGuardians $guardians): JsonResponse
    {
        $this->authorize($student);

        $phone = StudentInput::phone($request->query('hp'));

        if ($phone === null || preg_match(StudentInput::PHONE_PATTERN, $phone) !== 1) {
            return response()->json([
                'message' => 'Ketik nomor HP lengkap: hanya angka (boleh diawali +), 8–19 digit.',
                'results' => [],
            ], 422);
        }

        return response()->json([
            'phone' => $phone,
            'results' => $guardians->search($this->tenantId(), $student, $phone),
        ]);
    }

    public function store(Request $request, string $student, ManageGuardians $guardians): RedirectResponse
    {
        $this->authorize($student);

        $request->merge(['phone' => StudentInput::phone($request->input('phone')), 'nik' => PersonNik::normalize($request->input('nik'))]);

        $request->validate([
            'mode' => ['required', Rule::in(['baru', 'ada'])],
            'nik' => ['exclude_unless:mode,baru', 'nullable', 'string', 'regex:'.PersonNik::PATTERN],
            'relation' => ['required', Rule::enum(GuardianRelation::class)],
            'make_primary' => ['nullable', 'boolean'],
            'name' => ['exclude_unless:mode,baru', 'required', 'string', 'max:'.StudentInput::NAME_MAX_LENGTH],
            'gender' => ['exclude_unless:mode,baru', 'nullable', Rule::enum(Gender::class)],
            'phone' => ['nullable', 'string', 'regex:'.StudentInput::PHONE_PATTERN, 'required_if:mode,ada'],
            'membership_id' => ['exclude_unless:mode,ada', 'required', 'uuid'],
        ], [
            'relation.required' => 'Pilih hubungan.',
            'name.required' => 'Nama orang tua/wali wajib diisi.',
            'phone.regex' => 'Nomor HP hanya angka (boleh diawali +), 8–19 digit.',
            'phone.required_if' => 'Cari dulu lewat nomor HP.',
            'membership_id.required' => 'Pilih salah satu orang dari hasil pencarian.',
            'nik.regex' => 'NIK harus 16 angka.',
        ], $this->attributes());

        /** @var GuardianRelation $relation */
        $relation = $request->enum('relation', GuardianRelation::class);
        $makePrimary = $request->boolean('make_primary');

        if ($request->input('mode') === 'ada') {
            $this->attempt(fn (): StudentGuardian => $guardians->addExisting(
                $this->tenantId(),
                $student,
                $request->string('membership_id')->value(),
                (string) StudentInput::text($request->input('phone')),
                $relation,
                $makePrimary,
            ));

            return $this->done('Orang tua/wali ditautkan ke siswa ini.');
        }

        [, $reused] = $this->attempt(fn (): array => $guardians->addNew($this->tenantId(), $student, new GuardianData(
            relation: $relation,
            name: (string) StudentInput::name($request->input('name')),
            gender: $request->enum('gender', Gender::class),
            phone: StudentInput::text($request->input('phone')),
            nik: StudentInput::text($request->input('nik')),
        ), $makePrimary));

        return $this->done($reused
            ? 'Orang tua/wali ditambahkan memakai data yang sudah ada (NIK atau nama & HP sama).'
            : 'Orang tua/wali ditambahkan.');
    }

    public function update(Request $request, string $student, string $guardian, ManageGuardians $guardians): RedirectResponse
    {
        $this->authorize($student);

        $request->merge(['phone' => StudentInput::phone($request->input('phone')), 'nik' => PersonNik::normalize($request->input('nik'))]);

        $request->validate([
            'relation' => ['required', Rule::enum(GuardianRelation::class)],
            // Kosong = NIK tidak diubah (form tidak menampilkan NIK lengkap orang tua/wali).
            'nik' => ['nullable', 'string', 'regex:'.PersonNik::PATTERN],
            // Data diri hanya dikirim form bila orang ini bukan pegawai/siswa (service yang menentukan).
            'name' => ['nullable', 'string', 'max:'.StudentInput::NAME_MAX_LENGTH],
            'gender' => ['nullable', Rule::enum(Gender::class)],
            'phone' => ['nullable', 'string', 'regex:'.StudentInput::PHONE_PATTERN],
        ], [
            'relation.required' => 'Pilih hubungan.',
            'phone.regex' => 'Nomor HP hanya angka (boleh diawali +), 8–19 digit.',
            'nik.regex' => 'NIK harus 16 angka.',
        ], $this->attributes());

        /** @var GuardianRelation $relation */
        $relation = $request->enum('relation', GuardianRelation::class);

        $changed = $this->attempt(fn (): array => $guardians->update(
            $this->tenantId(),
            $student,
            $guardian,
            $relation,
            (string) StudentInput::name($request->input('name')),
            $request->enum('gender', Gender::class),
            StudentInput::text($request->input('phone')),
            StudentInput::text($request->input('nik')),
        ));

        return $this->done($changed === [] ? 'Tidak ada data yang berubah.' : 'Data orang tua/wali disimpan.');
    }

    public function destroy(Request $request, string $student, string $guardian, ManageGuardians $guardians): RedirectResponse
    {
        $this->authorize($student);

        $request->validate(['new_primary_id' => ['nullable', 'uuid']], [], ['new_primary_id' => 'kontak utama pengganti']);

        $this->attempt(fn (): bool => $this->unlink($guardians, $student, $guardian, StudentInput::text($request->input('new_primary_id'))));

        return $this->done('Orang tua/wali dilepas dari siswa ini. Datanya tetap tersimpan.');
    }

    public function makePrimary(string $student, string $guardian, ManageGuardians $guardians): RedirectResponse
    {
        $this->authorize($student);

        $this->attempt(fn (): bool => $this->primary($guardians, $student, $guardian));

        return $this->done('Kontak utama diganti.');
    }

    // ── Bantuan ───────────────────────────────────────────────────────────────

    private function authorize(string $studentId): void
    {
        if (! $this->visibility->canSee($studentId, AcademicAccess::STUDENTS_VIEW)) {
            abort(404);
        }

        if (! $this->access->canManageGuardians($studentId)) {
            abort(403);
        }
    }

    private function unlink(ManageGuardians $guardians, string $student, string $guardian, ?string $newPrimaryId): bool
    {
        $guardians->unlink($this->tenantId(), $student, $guardian, $newPrimaryId);

        return true;
    }

    private function primary(ManageGuardians $guardians, string $student, string $guardian): bool
    {
        $guardians->makePrimary($this->tenantId(), $student, $guardian);

        return true;
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
    private function attributes(): array
    {
        return [
            'mode' => 'cara menambah',
            'relation' => 'hubungan',
            'name' => 'nama orang tua/wali',
            'gender' => 'jenis kelamin',
            'phone' => 'nomor HP',
            'nik' => 'NIK',
            'membership_id' => 'orang yang dipilih',
            'make_primary' => 'kontak utama',
        ];
    }
}
