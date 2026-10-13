<?php

namespace Modules\HR\Http\Controllers;

use Illuminate\Database\Query\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Modules\Core\Application\Authorization\AuthorizationService;
use Modules\Core\Domain\Organization\Organization;
use Modules\Core\Domain\Organization\OrganizationStatus;
use Modules\Core\Domain\Person\Gender;
use Modules\Core\Domain\Tenancy\MembershipStatus;
use Modules\Core\Domain\Tenancy\TenantContext;
use Modules\Core\Support\BusinessClock;
use Modules\HR\Application\Authorization\HrAccess;
use Modules\HR\Application\Employee\CreateEmployee;
use Modules\HR\Application\Employee\NewEmployeeData;
use Modules\HR\Application\Employee\PersonalData;
use Modules\HR\Domain\Catalog\CatalogKind;
use Modules\HR\Domain\Employee\EducationLevel;
use Modules\HR\Domain\Employee\Employee;
use Modules\HR\Domain\Employee\Exceptions\DuplicatePersonException;
use Modules\HR\Domain\Employee\Exceptions\EmployeeException;

/**
 * Tambah pegawai (PRD-001 §6). Hanya Admin SDM / Admin Yayasan di tingkat Yayasan (OD-HR-02),
 * sehingga butuh `hr.employees.manage` dari role tenant-wide di lembaga kerja Seluruh Yayasan.
 */
final class EmployeeController
{
    /** Batas daftar "anggota yayasan yang belum menjadi pegawai" di form. */
    private const MEMBER_OPTIONS_LIMIT = 500;

    public function __construct(
        private readonly AuthorizationService $authorization,
        private readonly TenantContext $tenantContext,
    ) {}

    public function create(): Response
    {
        $this->authorize();

        return Inertia::render('hr/employees/create', [
            'today' => BusinessClock::todayString(),
            'members' => $this->memberOptions(),
            'options' => [
                'employmentTypes' => $this->catalogOptions(CatalogKind::EmploymentType),
                'classifications' => $this->catalogOptions(CatalogKind::Classification),
                'positions' => $this->catalogOptions(CatalogKind::Position),
                'organizations' => $this->organizationOptions(),
                'educationLevels' => array_map(fn (EducationLevel $level): array => [
                    'value' => $level->value,
                    'label' => $level->label(),
                ], EducationLevel::cases()),
                'genders' => array_map(fn (Gender $gender): array => [
                    'value' => $gender->value,
                    'label' => $gender->label(),
                ], Gender::cases()),
            ],
        ]);
    }

    public function store(Request $request, CreateEmployee $createEmployee): RedirectResponse
    {
        $this->authorize();

        // Rapikan dulu agar "0812-3456-789" atau "yys/001" tetap diterima.
        $request->merge([
            'phone' => PersonalData::phone($request->input('phone')),
            'employee_number' => PersonalData::employeeNumber($request->input('employee_number')),
            'nuptk' => PersonalData::nuptk($request->input('nuptk')),
        ]);

        $today = BusinessClock::todayString();

        $request->validate([
            'mode' => ['required', Rule::in(['baru', 'anggota'])],
            'membership_id' => ['nullable', 'required_if:mode,anggota', 'uuid'],
            'name' => ['nullable', 'required_if:mode,baru', 'string', 'max:200'],
            'gender' => ['nullable', 'required_if:mode,baru', Rule::enum(Gender::class)],
            'birth_place' => ['nullable', 'string', 'max:100'],
            'birth_date' => ['nullable', 'date_format:Y-m-d', 'before_or_equal:'.$today],
            'phone' => ['nullable', 'string', 'regex:'.PersonalData::PHONE_PATTERN],
            'address' => ['nullable', 'string', 'max:500'],
            'employee_number' => ['nullable', 'string', 'regex:'.PersonalData::EMPLOYEE_NUMBER_PATTERN],
            'nuptk' => ['nullable', 'string', 'regex:'.PersonalData::NUPTK_PATTERN],
            'education_level' => ['nullable', Rule::enum(EducationLevel::class)],
            'education_major' => ['nullable', 'string', 'max:150'],
            'employment_type_id' => ['required', 'uuid'],
            'classification_id' => ['nullable', 'uuid'],
            'start_date' => ['required', 'date_format:Y-m-d', 'before_or_equal:'.$today],
            'organization_id' => ['required', 'uuid'],
            'position_id' => ['required', 'uuid'],
            'confirm_duplicate' => ['nullable', 'boolean'],
        ], [
            'membership_id.required_if' => 'Pilih anggota yayasan.',
            'name.required_if' => 'Nama lengkap wajib diisi.',
            'gender.required_if' => 'Jenis kelamin wajib dipilih.',
            'birth_date.before_or_equal' => 'Tanggal lahir tidak boleh di masa depan.',
            'start_date.before_or_equal' => 'Tanggal mulai tidak boleh di masa depan.',
            'phone.regex' => 'Nomor HP hanya angka (boleh diawali +), 8–19 digit.',
            'employee_number.regex' => 'Nomor induk hanya huruf, angka, titik, garis miring, dan tanda "-", maksimal 30 karakter.',
            'nuptk.regex' => 'NUPTK harus 16 angka.',
            'employment_type_id.required' => 'Pilih jenis pegawai.',
            'organization_id.required' => 'Pilih lembaga penempatan.',
            'position_id.required' => 'Pilih jabatan.',
        ], $this->attributes());

        $isMember = $request->input('mode') === 'anggota';

        $data = new NewEmployeeData(
            tenantId: $this->tenantContext->require()->tenantId,
            membershipId: $isMember ? PersonalData::text($request->input('membership_id')) : null,
            name: $isMember ? null : PersonalData::text($request->input('name')),
            gender: $request->enum('gender', Gender::class),
            birthPlace: PersonalData::text($request->input('birth_place')),
            birthDate: PersonalData::text($request->input('birth_date')),
            phone: PersonalData::text($request->input('phone')),
            address: PersonalData::text($request->input('address')),
            employeeNumber: PersonalData::text($request->input('employee_number')),
            nuptk: PersonalData::text($request->input('nuptk')),
            educationLevel: $request->enum('education_level', EducationLevel::class),
            educationMajor: PersonalData::text($request->input('education_major')),
            employmentTypeId: $request->string('employment_type_id')->value(),
            classificationId: PersonalData::text($request->input('classification_id')),
            startDate: $request->string('start_date')->value(),
            organizationId: $request->string('organization_id')->value(),
            positionId: $request->string('position_id')->value(),
            confirmDuplicate: $request->boolean('confirm_duplicate'),
        );

        try {
            $employee = $createEmployee->handle($data);
        } catch (EmployeeException $exception) {
            throw ValidationException::withMessages([$exception->field => $exception->getMessage()]);
        } catch (DuplicatePersonException $exception) {
            throw ValidationException::withMessages(['duplicate' => $exception->getMessage()]);
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => $this->personName($employee).' berhasil ditambahkan sebagai pegawai.']);

        return to_route('hr.employees.create');
    }

    // ── Bantuan ───────────────────────────────────────────────────────────────

    /**
     * Pegawai hanya dikelola di tingkat Yayasan (OD-HR-02): 403 bila tidak punya izinnya.
     */
    private function authorize(): void
    {
        $this->authorization->inspectTarget(HrAccess::EMPLOYEES_MANAGE, null, null)->authorize();
    }

    /**
     * Item katalog yang masih aktif (yang nonaktif tidak bisa dipilih untuk data baru).
     *
     * @return list<array{value: string, label: string}>
     */
    private function catalogOptions(CatalogKind $kind): array
    {
        $options = [];

        foreach ($kind->query()->where('is_active', true)->orderBy('name')->get() as $item) {
            $options[] = ['value' => $item->id, 'label' => $item->name];
        }

        return $options;
    }

    /**
     * Lembaga aktif di yayasan ini (filter yayasan otomatis), urut kode.
     *
     * @return list<array{value: string, label: string}>
     */
    private function organizationOptions(): array
    {
        $options = [];

        $organizations = Organization::query()
            ->where('status', OrganizationStatus::Active->value)
            ->orderBy('code')
            ->get();

        foreach ($organizations as $organization) {
            $options[] = ['value' => $organization->id, 'label' => "{$organization->name} ({$organization->code})"];
        }

        return $options;
    }

    /**
     * Anggota aktif yayasan ini yang belum punya profil pegawai, untuk mode "Anggota yayasan".
     *
     * @return list<array{id: string, name: string, email: string|null, gender: string|null}>
     */
    private function memberOptions(): array
    {
        $rows = DB::table('memberships as m')
            ->join('persons as p', 'p.id', '=', 'm.person_id')
            ->leftJoin('users as u', 'u.person_id', '=', 'p.id')
            ->where('m.tenant_id', $this->tenantContext->require()->tenantId)
            ->where('m.status', MembershipStatus::Active->value)
            ->whereNotExists(function (Builder $query): void {
                $query->select(DB::raw(1))
                    ->from('hr_employees as e')
                    ->whereColumn('e.membership_id', 'm.id');
            })
            ->orderBy('p.name')
            ->limit(self::MEMBER_OPTIONS_LIMIT)
            ->get(['m.id', 'p.name', 'p.gender', 'u.email']);

        $members = [];

        foreach ($rows as $row) {
            $members[] = [
                'id' => (string) $row->id,
                'name' => (string) $row->name,
                'email' => $row->email === null ? null : (string) $row->email,
                'gender' => $row->gender === null ? null : (string) $row->gender,
            ];
        }

        return $members;
    }

    private function personName(Employee $employee): string
    {
        return (string) DB::table('memberships as m')
            ->join('persons as p', 'p.id', '=', 'm.person_id')
            ->where('m.id', $employee->membership_id)
            ->value('p.name');
    }

    /**
     * @return array<string, string>
     */
    private function attributes(): array
    {
        return [
            'membership_id' => 'anggota yayasan',
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
            'start_date' => 'tanggal mulai',
            'organization_id' => 'lembaga',
            'position_id' => 'jabatan',
        ];
    }
}
