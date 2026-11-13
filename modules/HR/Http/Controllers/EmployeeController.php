<?php

namespace Modules\HR\Http\Controllers;

use Illuminate\Database\Query\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Modules\Core\Application\Authorization\AuthorizationService;
use Modules\Core\Application\Authorization\CoreAccess;
use Modules\Core\Application\Authorization\MemberAccessSummary;
use Modules\Core\Application\Identity\LoginAccountSummary;
use Modules\Core\Application\Person\PersonNik;
use Modules\Core\Domain\Identity\User;
use Modules\Core\Domain\Organization\Organization;
use Modules\Core\Domain\Organization\OrganizationStatus;
use Modules\Core\Domain\Person\Gender;
use Modules\Core\Domain\Tenancy\MembershipStatus;
use Modules\Core\Domain\Tenancy\TenantContext;
use Modules\Core\Support\BusinessClock;
use Modules\HR\Application\Attendance\AttendanceRecap;
use Modules\HR\Application\Authorization\HrAccess;
use Modules\HR\Application\Discipline\DisciplineRecords;
use Modules\HR\Application\Discipline\ManageDiscipline;
use Modules\HR\Application\Document\DocumentFileStore;
use Modules\HR\Application\Document\EmployeeDocuments;
use Modules\HR\Application\Employee\CreateEmployee;
use Modules\HR\Application\Employee\EmployeeDirectory;
use Modules\HR\Application\Employee\EmployeeFilters;
use Modules\HR\Application\Employee\EmployeeProfile;
use Modules\HR\Application\Employee\EmployeeVisibility;
use Modules\HR\Application\Employee\NewEmployeeData;
use Modules\HR\Application\Employee\PersonalData;
use Modules\HR\Application\Leave\EmployeeLeaveSummary;
use Modules\HR\Application\Offboarding\ManageOffboarding;
use Modules\HR\Application\Offboarding\OffboardingChecklist;
use Modules\HR\Application\Recruitment\HireApplicant;
use Modules\HR\Application\Training\TrainingRecords;
use Modules\HR\Domain\Catalog\CatalogKind;
use Modules\HR\Domain\Catalog\DocumentType;
use Modules\HR\Domain\Employee\EducationLevel;
use Modules\HR\Domain\Employee\Employee;
use Modules\HR\Domain\Employee\EndReason;
use Modules\HR\Domain\Employee\Exceptions\DuplicatePersonException;
use Modules\HR\Domain\Employee\Exceptions\EmployeeException;
use Modules\HR\Domain\Recruitment\Exceptions\RecruitmentException;
use stdClass;

/**
 * Halaman pegawai (PRD-001 §6).
 *
 * - Daftar & detail: `hr.employees.view`; yang tampil disaring EmployeeVisibility (§5.3).
 *   Tanpa izin di mana pun → 403; pegawai di luar cakupan atau yayasan lain → 404 (OD-13).
 * - Tambah: hanya Admin SDM / Admin Yayasan di tingkat Yayasan (OD-HR-02), yaitu
 *   `hr.employees.manage` dari role tenant-wide di lembaga kerja Seluruh Yayasan.
 */
final class EmployeeController
{
    /** Batas daftar "anggota yayasan yang belum menjadi pegawai" di form. */
    private const MEMBER_OPTIONS_LIMIT = 500;

    public function __construct(
        private readonly AuthorizationService $authorization,
        private readonly TenantContext $tenantContext,
    ) {}

    public function index(Request $request, EmployeeDirectory $directory, EmployeeVisibility $visibility): Response
    {
        Gate::authorize(HrAccess::EMPLOYEES_VIEW);

        $filters = EmployeeFilters::fromArray($request->query->all());
        $page = $directory->search($filters);

        /** @var list<stdClass> $rows */
        $rows = array_values($page->items());

        return Inertia::render('hr/employees/index', [
            'employees' => [
                'data' => $directory->present($rows),
                'current_page' => $page->currentPage(),
                'last_page' => $page->lastPage(),
                'total' => $page->total(),
                'from' => $page->firstItem(),
                'to' => $page->lastItem(),
                'prev_page_url' => $page->previousPageUrl(),
                'next_page_url' => $page->nextPageUrl(),
            ],
            'filters' => $filters->toArray(),
            'options' => [
                'organizations' => $this->filterOrganizationOptions($visibility),
                'employmentTypes' => $this->catalogOptions(CatalogKind::EmploymentType, includeInactive: true),
                'classifications' => $this->catalogOptions(CatalogKind::Classification, includeInactive: true),
                'positions' => $this->catalogOptions(CatalogKind::Position, includeInactive: true),
            ],
            'canCreate' => $this->canManage(),
        ]);
    }

    public function show(
        Request $request,
        string $employee,
        EmployeeVisibility $visibility,
        EmployeeProfile $profile,
        LoginAccountSummary $loginAccounts,
        MemberAccessSummary $memberAccess,
        EmployeeLeaveSummary $leaveSummary,
        AttendanceRecap $attendanceRecap,
        EmployeeDocuments $employeeDocuments,
        DisciplineRecords $disciplineRecords,
        OffboardingChecklist $offboarding,
        TrainingRecords $trainingRecords,
    ): Response {
        Gate::authorize(HrAccess::EMPLOYEES_VIEW);

        // Di luar cakupan, milik yayasan lain, atau ID asal-asalan: 404 agar keberadaannya tidak bocor.
        if (! $visibility->canSee($employee, HrAccess::EMPLOYEES_VIEW)) {
            abort(404);
        }

        $withPersonal = $visibility->canSee($employee, HrAccess::EMPLOYEES_VIEW_PERSONAL);
        $data = $profile->load(
            $this->tenantContext->require()->tenantId,
            $employee,
            withPersonal: $withPersonal,
        );

        if ($data === null) {
            abort(404);
        }

        $canManage = $this->canManage();
        $viewer = $request->user();

        return Inertia::render('hr/employees/show', [
            'employee' => $data,
            'canManage' => $canManage,
            // Ringkasan cuti (HR-3d-b): hanya bagi yang boleh melihat data pribadi pegawai ini.
            'leave' => $withPersonal ? $leaveSummary->for($this->tenantContext->require()->tenantId, $employee) : null,
            // Ringkasan presensi bulan berjalan (HR-4c): hanya bagi yang boleh melihat data pribadi.
            'attendance' => $withPersonal ? $attendanceRecap->forEmployee($this->tenantContext->require()->tenantId, $employee) : null,
            // Dokumen & SK (HR-2a): hanya bagi yang boleh melihat data pribadi pegawai ini.
            'documents' => $withPersonal ? $employeeDocuments->forEmployee($this->tenantContext->require()->tenantId, $employee) : null,
            // Catatan disiplin (HR-7a): bagi pemegang hr.discipline.view atas pegawai ini (OD-HR-13).
            'discipline' => $visibility->canSee($employee, HrAccess::DISCIPLINE_VIEW) ? [
                'records' => $disciplineRecords->forEmployee($this->tenantContext->require()->tenantId, $employee),
                'manage' => $this->authorization->inspectTarget(HrAccess::DISCIPLINE_MANAGE, null, null)->allowed() ? [
                    'today' => BusinessClock::todayString(),
                    'kinds' => DisciplineController::kindOptions(),
                    'validMonths' => ManageDiscipline::DEFAULT_VALID_MONTHS,
                    'textMax' => ManageDiscipline::TEXT_MAX,
                    'reasonMax' => ManageDiscipline::REVOKE_REASON_MAX,
                    'fileMaxMb' => intdiv(ManageDiscipline::FILE_MAX_KILOBYTES, 1024),
                ] : null,
            ] : null,
            // Checklist offboarding (HR-7b): Admin SDM / Admin Yayasan, bila ada checklist atau bisa dibuatkan.
            'offboarding' => $canManage ? $this->offboardingData($offboarding, $employee) : null,
            // Riwayat pelatihan (HR-8a): bagi yang melihat pegawai ini; lampiran hanya dengan data pribadi.
            'trainings' => [
                'records' => $trainingRecords->forEmployee($this->tenantContext->require()->tenantId, $employee),
                'canDownload' => $withPersonal,
                'manage' => $canManage ? TrainingController::formOptions() : null,
            ],
            'canManageLeave' => $this->authorization->inspectTarget(HrAccess::LEAVE_MANAGE, null, null)->allowed(),
            // Bagian "Akses login" (PRD-000 §4.7): logika milik Core, hanya untuk Admin Yayasan (OD-21).
            'access' => $this->canManageAccess()
                ? $this->accessData($employee, $viewer instanceof User ? $viewer : null, $loginAccounts, $memberAccess)
                : null,
            // Bahan form aksi HR-1d, hanya untuk Admin SDM / Admin Yayasan.
            'manage' => $canManage ? [
                'today' => BusinessClock::todayString(),
                'values' => $profile->editValues($this->tenantContext->require()->tenantId, $employee),
                'options' => [
                    'employmentTypes' => $this->catalogOptions(CatalogKind::EmploymentType),
                    'classifications' => $this->catalogOptions(CatalogKind::Classification),
                    'positions' => $this->catalogOptions(CatalogKind::Position),
                    'documentTypes' => $this->documentTypeOptions(),
                    'documentMaxMb' => intdiv(DocumentFileStore::MAX_KILOBYTES, 1024),
                    'organizations' => $this->organizationOptions(),
                    'educationLevels' => $this->educationOptions(),
                    'genders' => $this->genderOptions(),
                    'endReasons' => array_map(fn (EndReason $reason): array => [
                        'value' => $reason->value,
                        'label' => $reason->label(),
                    ], array_filter(EndReason::cases(), fn (EndReason $reason): bool => $reason !== EndReason::StatusChanged)),
                ],
            ] : null,
        ]);
    }

    public function create(Request $request, HireApplicant $hire): Response
    {
        $this->authorize();

        return Inertia::render('hr/employees/create', [
            'today' => BusinessClock::todayString(),
            'applicant' => $this->applicantPrefill($request->string('pelamar')->value(), $hire),
            'members' => $this->memberOptions(),
            'options' => [
                'employmentTypes' => $this->catalogOptions(CatalogKind::EmploymentType),
                'classifications' => $this->catalogOptions(CatalogKind::Classification),
                'positions' => $this->catalogOptions(CatalogKind::Position),
                'organizations' => $this->organizationOptions(),
                'educationLevels' => $this->educationOptions(),
                'genders' => $this->genderOptions(),
            ],
        ]);
    }

    public function store(Request $request, CreateEmployee $createEmployee, HireApplicant $hire): RedirectResponse
    {
        $this->authorize();

        // Rapikan dulu agar "0812-3456-789" atau "yys/001" tetap diterima.
        $request->merge([
            'phone' => PersonalData::phone($request->input('phone')),
            'employee_number' => PersonalData::employeeNumber($request->input('employee_number')),
            'nuptk' => PersonalData::nuptk($request->input('nuptk')),
            'nik' => PersonNik::normalize($request->input('nik')),
        ]);

        $today = BusinessClock::todayString();

        $request->validate([
            'mode' => ['required', Rule::in(['baru', 'anggota'])],
            'membership_id' => ['nullable', 'required_if:mode,anggota', 'uuid'],
            'name' => ['nullable', 'required_if:mode,baru', 'string', 'max:200'],
            // Wajib untuk orang baru; untuk anggota yang belum punya NIK dicek di CreateEmployee.
            'nik' => ['nullable', 'required_if:mode,baru', 'string', 'regex:'.PersonNik::PATTERN],
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
            'applicant_id' => ['nullable', 'uuid'],
        ], [
            'membership_id.required_if' => 'Pilih anggota yayasan.',
            'name.required_if' => 'Nama lengkap wajib diisi.',
            'nik.required_if' => 'NIK wajib diisi untuk pegawai baru (16 angka sesuai KTP/KK).',
            'nik.regex' => 'NIK harus 16 angka.',
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
            nik: PersonalData::text($request->input('nik')),
            requireNik: true,
        );

        // Jadikan pegawai dari pelamar Diterima (PRD-001 §20.2, HR-9b): hanya orang baru & butuh izin kelola rekrutmen.
        $applicantId = PersonalData::text($request->input('applicant_id'));

        if ($applicantId !== null && ($isMember || ! $this->authorization->inspectTarget(HrAccess::RECRUITMENT_MANAGE, null, null)->allowed())) {
            throw ValidationException::withMessages(['form' => 'Pelamar hanya bisa dijadikan pegawai sebagai orang baru oleh pengelola rekrutmen.']);
        }

        try {
            $employee = $applicantId === null ? $createEmployee->handle($data) : $hire->handle($applicantId, $data);
        } catch (EmployeeException $exception) {
            throw ValidationException::withMessages([$exception->field => $exception->getMessage()]);
        } catch (DuplicatePersonException $exception) {
            throw ValidationException::withMessages(['duplicate' => $exception->getMessage()]);
        } catch (RecruitmentException $exception) {
            throw ValidationException::withMessages([$exception->field => $exception->getMessage()]);
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => $this->personName($employee).' berhasil ditambahkan sebagai pegawai.']);

        return to_route('hr.employees.show', ['employee' => $employee->id]);
    }

    // ── Bantuan ───────────────────────────────────────────────────────────────

    /**
     * Isian awal form dari pelamar Diterima yang belum dijadikan pegawai (HR-9b); null bila tidak berlaku.
     *
     * @return array{id: string, name: string, gender: string, phone: string|null, education_level: string|null, education_major: string|null, organization_id: string, position_id: string, vacancy_id: string, vacancy_title: string}|null
     */
    private function applicantPrefill(string $applicantId, HireApplicant $hire): ?array
    {
        if ($applicantId === '' || ! $this->authorization->inspectTarget(HrAccess::RECRUITMENT_MANAGE, null, null)->allowed()) {
            return null;
        }

        $applicant = $hire->candidate($this->tenantContext->require()->tenantId, $applicantId);

        if ($applicant === null) {
            return null;
        }

        return [
            'id' => (string) $applicant->id,
            'name' => (string) $applicant->name,
            'gender' => (string) $applicant->gender,
            'phone' => $applicant->phone === null ? null : (string) $applicant->phone,
            'education_level' => $applicant->education_level === null ? null : (string) $applicant->education_level,
            'education_major' => $applicant->education_major === null ? null : (string) $applicant->education_major,
            'organization_id' => (string) $applicant->organization_id,
            'position_id' => (string) $applicant->position_id,
            'vacancy_id' => (string) $applicant->vacancy_id,
            'vacancy_title' => (string) $applicant->vacancy_title,
        ];
    }

    /**
     * Pegawai hanya dikelola di tingkat Yayasan (OD-HR-02): 403 bila tidak punya izinnya.
     */
    private function authorize(): void
    {
        $this->authorization->inspectTarget(HrAccess::EMPLOYEES_MANAGE, null, null)->authorize();
    }

    /**
     * @return list<array{value: string, label: string}>
     */
    private function educationOptions(): array
    {
        return array_map(fn (EducationLevel $level): array => [
            'value' => $level->value,
            'label' => $level->label(),
        ], EducationLevel::cases());
    }

    /**
     * @return list<array{value: string, label: string}>
     */
    private function genderOptions(): array
    {
        return array_map(fn (Gender $gender): array => [
            'value' => $gender->value,
            'label' => $gender->label(),
        ], Gender::cases());
    }

    private function canManage(): bool
    {
        return $this->authorization->inspectTarget(HrAccess::EMPLOYEES_MANAGE, null, null)->allowed();
    }

    /**
     * @return array<string, mixed>|null
     */
    private function offboardingData(OffboardingChecklist $offboarding, string $employeeId): ?array
    {
        $data = $offboarding->forEmployee($this->tenantContext->require()->tenantId, $employeeId);

        if ($data['checklists'] === [] && ! $data['canStart']) {
            return null;
        }

        return $data + ['canManageAccess' => $this->canManageAccess(), 'noteMax' => ManageOffboarding::NOTE_MAX];
    }

    private function canManageAccess(): bool
    {
        return $this->authorization->inspectTarget(CoreAccess::MEMBERSHIPS_MANAGE, null, null)->allowed();
    }

    /**
     * Bagian "Akses login" (PRD-000 §4.7): akun (F5a) + role tingkat Yayasan & penugasan (F5b).
     * Datanya disusun Core; HR hanya meneruskan.
     *
     * @return array<string, mixed>|null
     */
    private function accessData(string $employeeId, ?User $viewer, LoginAccountSummary $loginAccounts, MemberAccessSummary $memberAccess): ?array
    {
        $context = $this->tenantContext->require();
        $membershipId = (string) Employee::query()->whereKey($employeeId)->value('membership_id');

        $login = $loginAccounts->forMembership($context->tenantId, $membershipId, $viewer);

        if ($login === null) {
            return null;
        }

        return [...$login, 'member' => $memberAccess->forMembership($context->tenantId, $membershipId, $context->membershipId)];
    }

    /**
     * Pilihan lembaga untuk saringan daftar: node yang boleh dilihat (nonaktif ikut, untuk riwayat).
     *
     * @return list<array{value: string, label: string}>
     */
    private function filterOrganizationOptions(EmployeeVisibility $visibility): array
    {
        $query = Organization::query()->orderBy('code');

        if (! $visibility->seesWholeTenant(HrAccess::EMPLOYEES_VIEW)) {
            $query->whereIn('id', $this->authorization->visibleNodeIds(HrAccess::EMPLOYEES_VIEW));
        }

        $options = [];

        foreach ($query->get() as $organization) {
            $suffix = $organization->isActive() ? '' : ' — nonaktif';
            $options[] = ['value' => $organization->id, 'label' => "{$organization->name} ({$organization->code}){$suffix}"];
        }

        return $options;
    }

    /**
     * Item katalog. Untuk data baru hanya yang aktif; untuk saringan daftar, yang nonaktif ikut (riwayat).
     *
     * @return list<array{value: string, label: string}>
     */
    private function catalogOptions(CatalogKind $kind, bool $includeInactive = false): array
    {
        $options = [];

        $items = $kind->query()
            ->when(! $includeInactive, fn ($query) => $query->where('is_active', true))
            ->orderBy('name')
            ->get();

        foreach ($items as $item) {
            $options[] = ['value' => $item->id, 'label' => $item->is_active ? $item->name : "{$item->name} — nonaktif"];
        }

        return $options;
    }

    /**
     * Jenis dokumen (HR-2a), termasuk yang nonaktif agar dokumen lama tetap bisa diubah isiannya.
     *
     * @return list<array{value: string, label: string, has_expiry: bool, is_active: bool}>
     */
    private function documentTypeOptions(): array
    {
        $options = [];

        foreach (DocumentType::query()->orderByDesc('is_active')->orderBy('name')->get() as $type) {
            $options[] = [
                'value' => $type->id,
                'label' => $type->is_active ? $type->name : "{$type->name} — nonaktif",
                'has_expiry' => $type->has_expiry,
                'is_active' => $type->is_active,
            ];
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
     * @return list<array{id: string, name: string, email: string|null, gender: string|null, has_nik: bool}>
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
            ->get(['m.id', 'p.id as person_id', 'p.name', 'p.gender', 'u.email']);

        $withNik = app(PersonNik::class)->personsWithNik(array_values(array_map(fn (stdClass $row): string => (string) $row->person_id, $rows->all())));
        $members = [];

        foreach ($rows as $row) {
            $members[] = [
                'id' => (string) $row->id,
                'name' => (string) $row->name,
                'email' => $row->email === null ? null : (string) $row->email,
                'gender' => $row->gender === null ? null : (string) $row->gender,
                // Hanya ada/tidaknya NIK, bukan nilainya: menentukan apakah kolom NIK wajib diisi.
                'has_nik' => isset($withNik[(string) $row->person_id]),
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
            'nik' => 'NIK',
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
