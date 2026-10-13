<?php

namespace Modules\HR\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Modules\Core\Application\Authorization\AuthorizationService;
use Modules\Core\Domain\Person\Gender;
use Modules\Core\Domain\Tenancy\TenantContext;
use Modules\Core\Support\BusinessClock;
use Modules\HR\Application\Authorization\HrAccess;
use Modules\HR\Application\Employee\ChangeEmploymentStatus;
use Modules\HR\Application\Employee\EmployeeProfileData;
use Modules\HR\Application\Employee\EndEmployment;
use Modules\HR\Application\Employee\ManagePlacements;
use Modules\HR\Application\Employee\PersonalData;
use Modules\HR\Application\Employee\RehireEmployee;
use Modules\HR\Application\Employee\UpdateEmployeeProfile;
use Modules\HR\Domain\Employee\EducationLevel;
use Modules\HR\Domain\Employee\Employee;
use Modules\HR\Domain\Employee\EndReason;
use Modules\HR\Domain\Employee\Exceptions\EmployeeException;
use Modules\HR\Domain\Employee\Placement;

/**
 * Aksi di halaman detail pegawai (HR-1d, PRD-001 §6): ubah profil, ganti status, penempatan,
 * berhentikan, aktifkan kembali.
 *
 * Hanya Admin SDM / Admin Yayasan di tingkat Yayasan (OD-HR-02): tanpa izin → 403.
 * Pegawai/penempatan yang tidak ada di yayasan ini → 404. Aturan bisnis ada di service;
 * pelanggarannya tampil sebagai pesan di field form terkait.
 */
final class EmployeeActionController
{
    public function __construct(
        private readonly AuthorizationService $authorization,
        private readonly TenantContext $tenantContext,
    ) {}

    public function updateProfile(Request $request, string $employee, UpdateEmployeeProfile $updateProfile): RedirectResponse
    {
        $target = $this->authorizeAndFind($employee);

        $request->merge([
            'phone' => PersonalData::phone($request->input('phone')),
            'employee_number' => PersonalData::employeeNumber($request->input('employee_number')),
            'nuptk' => PersonalData::nuptk($request->input('nuptk')),
        ]);

        $request->validate([
            'name' => ['required', 'string', 'max:200'],
            'gender' => ['required', Rule::enum(Gender::class)],
            'birth_place' => ['nullable', 'string', 'max:100'],
            'birth_date' => ['nullable', 'date_format:Y-m-d', 'before_or_equal:'.BusinessClock::todayString()],
            'phone' => ['nullable', 'string', 'regex:'.PersonalData::PHONE_PATTERN],
            'address' => ['nullable', 'string', 'max:500'],
            'employee_number' => ['nullable', 'string', 'regex:'.PersonalData::EMPLOYEE_NUMBER_PATTERN],
            'nuptk' => ['nullable', 'string', 'regex:'.PersonalData::NUPTK_PATTERN],
            'education_level' => ['nullable', Rule::enum(EducationLevel::class)],
            'education_major' => ['nullable', 'string', 'max:150'],
        ], [
            'birth_date.before_or_equal' => 'Tanggal lahir tidak boleh di masa depan.',
            'phone.regex' => 'Nomor HP hanya angka (boleh diawali +), 8–19 digit.',
            'employee_number.regex' => 'Nomor induk hanya huruf, angka, titik, garis miring, dan tanda "-", maksimal 30 karakter.',
            'nuptk.regex' => 'NUPTK harus 16 angka.',
        ], $this->attributes());

        /** @var Gender $gender */
        $gender = $request->enum('gender', Gender::class);

        $this->attempt(fn () => $updateProfile->handle($this->tenantId(), $target->id, new EmployeeProfileData(
            name: (string) PersonalData::text($request->input('name')),
            gender: $gender,
            birthPlace: PersonalData::text($request->input('birth_place')),
            birthDate: PersonalData::text($request->input('birth_date')),
            phone: PersonalData::text($request->input('phone')),
            address: PersonalData::text($request->input('address')),
            employeeNumber: PersonalData::text($request->input('employee_number')),
            nuptk: PersonalData::text($request->input('nuptk')),
            educationLevel: $request->enum('education_level', EducationLevel::class),
            educationMajor: PersonalData::text($request->input('education_major')),
        )));

        return $this->done('Data pegawai disimpan.');
    }

    public function changeStatus(Request $request, string $employee, ChangeEmploymentStatus $changeStatus): RedirectResponse
    {
        $target = $this->authorizeAndFind($employee);

        $request->validate([
            'employment_type_id' => ['required', 'uuid'],
            'classification_id' => ['nullable', 'uuid'],
            'effective_date' => ['required', 'date_format:Y-m-d', 'before_or_equal:'.BusinessClock::todayString()],
        ], [
            'employment_type_id.required' => 'Pilih jenis pegawai.',
            'effective_date.before_or_equal' => 'Tanggal berlaku tidak boleh di masa depan.',
        ], $this->attributes());

        $this->attempt(fn () => $changeStatus->handle(
            $this->tenantId(),
            $target->id,
            $request->string('employment_type_id')->value(),
            PersonalData::text($request->input('classification_id')),
            $request->string('effective_date')->value(),
        ));

        return $this->done('Status kepegawaian diganti.');
    }

    public function addPlacement(Request $request, string $employee, ManagePlacements $placements): RedirectResponse
    {
        $target = $this->authorizeAndFind($employee);

        $request->validate([
            'organization_id' => ['required', 'uuid'],
            'position_id' => ['required', 'uuid'],
            'start_date' => ['required', 'date_format:Y-m-d', 'before_or_equal:'.BusinessClock::todayString()],
            'make_primary' => ['nullable', 'boolean'],
        ], [
            'organization_id.required' => 'Pilih lembaga.',
            'position_id.required' => 'Pilih jabatan.',
            'start_date.before_or_equal' => 'Tanggal mulai tidak boleh di masa depan.',
        ], $this->attributes());

        $this->attempt(fn () => $placements->add(
            $this->tenantId(),
            $target->id,
            $request->string('organization_id')->value(),
            $request->string('position_id')->value(),
            $request->string('start_date')->value(),
            $request->boolean('make_primary'),
        ));

        return $this->done('Penempatan ditambahkan.');
    }

    public function endPlacement(Request $request, string $employee, string $placement, ManagePlacements $placements): RedirectResponse
    {
        $target = $this->authorizeAndFind($employee);
        $this->findPlacement($target, $placement);

        $request->validate([
            'end_date' => ['required', 'date_format:Y-m-d', 'before_or_equal:'.BusinessClock::todayString()],
            'new_primary_id' => ['nullable', 'uuid'],
        ], [
            'end_date.before_or_equal' => 'Tanggal berakhir tidak boleh di masa depan.',
        ], $this->attributes());

        $this->attempt(fn () => $placements->end(
            $this->tenantId(),
            $target->id,
            $placement,
            $request->string('end_date')->value(),
            PersonalData::text($request->input('new_primary_id')),
        ));

        return $this->done('Penempatan diakhiri.');
    }

    public function transfer(Request $request, string $employee, string $placement, ManagePlacements $placements): RedirectResponse
    {
        $target = $this->authorizeAndFind($employee);
        $this->findPlacement($target, $placement);

        $request->validate([
            'organization_id' => ['required', 'uuid'],
            'position_id' => ['required', 'uuid'],
            'effective_date' => ['required', 'date_format:Y-m-d', 'before_or_equal:'.BusinessClock::todayString()],
        ], [
            'organization_id.required' => 'Pilih lembaga tujuan.',
            'position_id.required' => 'Pilih jabatan.',
            'effective_date.before_or_equal' => 'Tanggal mutasi tidak boleh di masa depan.',
        ], $this->attributes());

        $this->attempt(fn () => $placements->transfer(
            $this->tenantId(),
            $target->id,
            $placement,
            $request->string('organization_id')->value(),
            $request->string('position_id')->value(),
            $request->string('effective_date')->value(),
        ));

        return $this->done('Mutasi disimpan.');
    }

    public function makePrimary(string $employee, string $placement, ManagePlacements $placements): RedirectResponse
    {
        $target = $this->authorizeAndFind($employee);
        $this->findPlacement($target, $placement);

        $this->attempt(fn () => $placements->makePrimary($this->tenantId(), $target->id, $placement));

        return $this->done('Penempatan utama diganti.');
    }

    public function end(Request $request, string $employee, EndEmployment $endEmployment): RedirectResponse
    {
        $target = $this->authorizeAndFind($employee);

        $request->validate([
            'end_reason' => ['required', Rule::enum(EndReason::class)->except([EndReason::StatusChanged])],
            'end_note' => ['nullable', 'string', 'max:500'],
            'end_date' => ['required', 'date_format:Y-m-d', 'before_or_equal:'.BusinessClock::todayString()],
        ], [
            'end_reason.required' => 'Pilih alasan berhenti.',
            'end_date.before_or_equal' => 'Tanggal berhenti tidak boleh di masa depan.',
        ], $this->attributes());

        /** @var EndReason $reason */
        $reason = $request->enum('end_reason', EndReason::class);

        $this->attempt(fn () => $endEmployment->handle(
            $this->tenantId(),
            $target->id,
            $reason,
            PersonalData::text($request->input('end_note')),
            $request->string('end_date')->value(),
        ));

        return $this->done('Pegawai diberhentikan. Akun login (bila ada) tidak berubah.');
    }

    public function rehire(Request $request, string $employee, RehireEmployee $rehire): RedirectResponse
    {
        $target = $this->authorizeAndFind($employee);

        $request->validate([
            'employment_type_id' => ['required', 'uuid'],
            'classification_id' => ['nullable', 'uuid'],
            'start_date' => ['required', 'date_format:Y-m-d', 'before_or_equal:'.BusinessClock::todayString()],
            'organization_id' => ['required', 'uuid'],
            'position_id' => ['required', 'uuid'],
        ], [
            'employment_type_id.required' => 'Pilih jenis pegawai.',
            'organization_id.required' => 'Pilih lembaga penempatan.',
            'position_id.required' => 'Pilih jabatan.',
            'start_date.before_or_equal' => 'Tanggal mulai tidak boleh di masa depan.',
        ], $this->attributes());

        $this->attempt(fn () => $rehire->handle(
            $this->tenantId(),
            $target->id,
            $request->string('employment_type_id')->value(),
            PersonalData::text($request->input('classification_id')),
            $request->string('start_date')->value(),
            $request->string('organization_id')->value(),
            $request->string('position_id')->value(),
        ));

        return $this->done('Pegawai diaktifkan kembali.');
    }

    // ── Bantuan ───────────────────────────────────────────────────────────────

    /**
     * 403 bila bukan pengelola tingkat Yayasan; 404 bila pegawai tidak ada di yayasan ini.
     */
    private function authorizeAndFind(string $employeeId): Employee
    {
        $this->authorization->inspectTarget(HrAccess::EMPLOYEES_MANAGE, null, null)->authorize();

        $employee = Str::isUuid($employeeId) ? Employee::query()->find($employeeId) : null;

        if (! $employee instanceof Employee) {
            abort(404);
        }

        return $employee;
    }

    private function findPlacement(Employee $employee, string $placementId): Placement
    {
        $placement = Str::isUuid($placementId)
            ? Placement::query()->where('employee_id', $employee->id)->find($placementId)
            : null;

        if (! $placement instanceof Placement) {
            abort(404);
        }

        return $placement;
    }

    private function tenantId(): string
    {
        return $this->tenantContext->require()->tenantId;
    }

    /**
     * Jalankan service; pelanggaran aturan tampil sebagai pesan di field form terkait.
     *
     * @param  callable(): mixed  $action
     */
    private function attempt(callable $action): void
    {
        try {
            $action();
        } catch (EmployeeException $exception) {
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
            'name' => 'nama lengkap',
            'gender' => 'jenis kelamin',
            'birth_place' => 'tempat lahir',
            'birth_date' => 'tanggal lahir',
            'phone' => 'nomor HP',
            'address' => 'alamat',
            'employee_number' => 'nomor induk',
            'nuptk' => 'NUPTK',
            'education_level' => 'pendidikan terakhir',
            'education_major' => 'program studi/jurusan',
            'employment_type_id' => 'jenis pegawai',
            'classification_id' => 'status kepegawaian',
            'effective_date' => 'tanggal berlaku',
            'organization_id' => 'lembaga',
            'position_id' => 'jabatan',
            'start_date' => 'tanggal mulai',
            'end_date' => 'tanggal',
            'new_primary_id' => 'penempatan pengganti',
            'end_reason' => 'alasan berhenti',
            'end_note' => 'catatan',
        ];
    }
}
