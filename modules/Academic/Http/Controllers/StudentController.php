<?php

namespace Modules\Academic\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Modules\Academic\Application\Authorization\AcademicAccess;
use Modules\Academic\Application\Calendar\AcademicCalendarResolver;
use Modules\Academic\Application\Classroom\ClassDirectory;
use Modules\Academic\Application\Student\CreateStudent;
use Modules\Academic\Application\Student\DuplicateCandidates;
use Modules\Academic\Application\Student\GuardianData;
use Modules\Academic\Application\Student\NewStudentData;
use Modules\Academic\Application\Student\StudentDirectory;
use Modules\Academic\Application\Student\StudentFilters;
use Modules\Academic\Application\Student\StudentInput;
use Modules\Academic\Application\Student\StudentManageAccess;
use Modules\Academic\Application\Student\StudentProfile;
use Modules\Academic\Application\Student\StudentVisibility;
use Modules\Academic\Domain\Classroom\SchoolClass;
use Modules\Academic\Domain\Student\EnrollmentEndReason;
use Modules\Academic\Domain\Student\EntryType;
use Modules\Academic\Domain\Student\Exceptions\StudentCheckFailed;
use Modules\Academic\Domain\Student\Exceptions\StudentException;
use Modules\Academic\Domain\Student\GuardianRelation;
use Modules\Core\Application\Authorization\AuthorizationService;
use Modules\Core\Domain\Organization\Organization;
use Modules\Core\Domain\Organization\OrganizationStatus;
use Modules\Core\Domain\Organization\OrganizationType;
use Modules\Core\Domain\Person\Gender;
use Modules\Core\Domain\Tenancy\TenantContext;
use Modules\Core\Support\BusinessClock;
use stdClass;

/**
 * Halaman siswa (PRD-002 §6): daftar & detail (AC-1c-a), tambah siswa (AC-1b). Aksi di detail ada di
 * StudentActionController (AC-1c-b); detail mengirim bahan form-nya (`manage`) hanya kepada pengelola.
 *
 * Daftar & detail: `academic.students.view`, disaring per pohon lembaga (StudentVisibility, §5.3);
 * siswa di luar cakupan → 404. Data pribadi & orang tua/wali hanya dengan `view_personal`.
 *
 * Operator Akademik menambah siswa di lembaganya; Admin Yayasan di semua lembaga
 * (`academic.students.manage`, OD-AC-04). Lembaga tujuan dicek lewat Gate: di luar cakupan → 404,
 * di dalam cakupan tanpa izin → 403.
 */
final class StudentController
{
    /** Calon orang ganda disimpan sesaat di session agar tampil lagi di form setelah redirect. */
    private const CANDIDATES_SESSION_KEY = 'academic.student_candidates';

    public function __construct(
        private readonly AuthorizationService $authorization,
        private readonly TenantContext $tenantContext,
    ) {}

    public function index(Request $request, StudentDirectory $directory, StudentVisibility $visibility): Response
    {
        Gate::authorize(AcademicAccess::STUDENTS_VIEW);

        $filters = StudentFilters::fromArray($request->query->all());
        $page = $directory->search($filters);

        /** @var list<stdClass> $rows */
        $rows = array_values($page->items());

        return Inertia::render('academic/students/index', [
            'students' => [
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
            ],
            'canCreate' => $this->authorization->allows(AcademicAccess::STUDENTS_MANAGE),
        ]);
    }

    public function show(string $student, StudentVisibility $visibility, StudentProfile $profile, StudentManageAccess $access, ClassDirectory $classDirectory): Response
    {
        Gate::authorize(AcademicAccess::STUDENTS_VIEW);

        // Di luar cakupan, milik yayasan lain, atau ID asal-asalan: 404 agar keberadaannya tidak bocor.
        if (! $visibility->canSee($student, AcademicAccess::STUDENTS_VIEW)) {
            abort(404);
        }

        $tenantId = $this->tenantContext->require()->tenantId;
        $data = $profile->load(
            $tenantId,
            $student,
            withPersonal: $visibility->canSee($student, AcademicAccess::STUDENTS_VIEW_PERSONAL),
        );

        if ($data === null) {
            abort(404);
        }

        $managesAnywhere = $access->managesAnywhere();
        $canReopen = $access->canReopen();

        // Penanda per pendaftaran: boleh diubah/ditutup oleh pengguna ini (lembaganya di cakupan kelola).
        $enrollments = ['current' => [], 'history' => []];

        foreach (['current', 'history'] as $group) {
            foreach ($data['enrollments'][$group] as $enrollment) {
                $enrollment['can_manage'] = $managesAnywhere && $access->canManageOrganization($enrollment['organization_id']);
                // Buka kembali (§18 P2): pendaftaran yang sudah ditutup, Admin Yayasan saja.
                $enrollment['can_reopen'] = $canReopen && ! $enrollment['open'];
                $enrollments[$group][] = $enrollment;
            }
        }

        $data['enrollments'] = $enrollments;

        // Kelas (AC-1d-b): pindah/keluarkan hanya bagi pengelola kelas di lembaga itu, tahun belum lewat.
        $classes = [];

        foreach ($data['classes'] as $row) {
            $canManageClass = $row['open'] && ! $row['past'] && $this->authorization->allows(AcademicAccess::CLASSES_MANAGE, $row['organization_id']);
            $row['can_manage'] = $canManageClass;
            $row['move_targets'] = $canManageClass ? $classDirectory->moveTargets($row['organization_id'], $row['academic_year_id'], $row['class_id']) : [];
            $classes[] = $row;
        }

        $data['classes'] = $classes;

        $manage = null;

        if ($managesAnywhere) {
            $canEditProfile = $access->canEditProfile($student);

            $manage = [
                'today' => BusinessClock::todayString(),
                'profile' => $canEditProfile ? $profile->editValues($tenantId, $student) : null,
                // Orang tua/wali: aturannya sama dengan data diri (AC-1c-b2).
                'guardians' => $canEditProfile,
                'maxGuardians' => StudentInput::MAX_GUARDIANS,
                'enrollTargets' => $access->enrollTargets($student),
                'options' => [
                    'genders' => $this->enumOptions(Gender::cases()),
                    'entryTypes' => $this->enumOptions(EntryType::cases()),
                    'endReasons' => $this->enumOptions(EnrollmentEndReason::cases()),
                    'relations' => $this->enumOptions(GuardianRelation::cases()),
                ],
            ];
        }

        return Inertia::render('academic/students/show', [
            'student' => $data,
            'canCreate' => $managesAnywhere,
            'manage' => $manage,
            'today' => BusinessClock::todayString(),
        ]);
    }

    public function create(Request $request, AcademicCalendarResolver $calendars): Response
    {
        $this->authorizeAnywhere();
        $organizations = $this->organizationOptions();

        return Inertia::render('academic/students/create', [
            'today' => BusinessClock::todayString(),
            'candidates' => $this->flashedCandidates($request),
            'options' => [
                'organizations' => $organizations,
                'genders' => $this->enumOptions(Gender::cases()),
                'entryTypes' => $this->enumOptions(EntryType::cases()),
                'relations' => $this->enumOptions(GuardianRelation::cases()),
                'classes' => $this->classOptions($calendars, $organizations),
            ],
            'maxGuardians' => StudentInput::MAX_GUARDIANS,
        ]);
    }

    public function store(Request $request, CreateStudent $createStudent, DuplicateCandidates $duplicateCandidates): RedirectResponse
    {
        $this->authorizeAnywhere();

        // Operator sedang menjawab peringatan orang ganda: bila simpan ulang gagal karena isian lain,
        // daftar calon tetap tampil agar pilihannya tidak hilang.
        $typedName = StudentInput::name($request->input('name'));

        if ($typedName !== null && ($request->filled('existing_membership_id') || $request->boolean('confirm_new'))) {
            $request->session()->flash(
                self::CANDIDATES_SESSION_KEY,
                $duplicateCandidates->find($this->tenantContext->require()->tenantId, $typedName),
            );
        }

        // Rapikan dulu agar "0812-3456-789" atau "nis/001" tetap diterima.
        $guardiansInput = [];

        foreach (array_values((array) $request->input('guardians', [])) as $row) {
            $row = (array) $row;
            $row['phone'] = StudentInput::phone($row['phone'] ?? null);
            $guardiansInput[] = $row;
        }

        $request->merge([
            'phone' => StudentInput::phone($request->input('phone')),
            'nisn' => StudentInput::nisn($request->input('nisn')),
            'student_number' => StudentInput::studentNumber($request->input('student_number')),
            'guardians' => $guardiansInput,
        ]);

        $today = BusinessClock::todayString();

        $request->validate([
            'name' => ['required', 'string', 'max:'.StudentInput::NAME_MAX_LENGTH],
            'gender' => ['nullable', Rule::enum(Gender::class)],
            'birth_place' => ['nullable', 'string', 'max:100'],
            'birth_date' => ['nullable', 'date_format:Y-m-d', 'before_or_equal:'.$today],
            'phone' => ['nullable', 'string', 'regex:'.StudentInput::PHONE_PATTERN],
            'address' => ['nullable', 'string', 'max:500'],
            'nisn' => ['nullable', 'string', 'regex:'.StudentInput::NISN_PATTERN],
            'organization_id' => ['required', 'uuid'],
            'student_number' => ['nullable', 'string', 'regex:'.StudentInput::STUDENT_NUMBER_PATTERN],
            'start_date' => ['required', 'date_format:Y-m-d', 'before_or_equal:'.$today],
            'entry_type' => ['required', Rule::enum(EntryType::class)],
            'guardians' => ['array', 'max:'.StudentInput::MAX_GUARDIANS],
            'guardians.*.relation' => ['required', Rule::enum(GuardianRelation::class)],
            'guardians.*.name' => ['required', 'string', 'max:'.StudentInput::NAME_MAX_LENGTH],
            'guardians.*.gender' => ['nullable', Rule::enum(Gender::class)],
            'guardians.*.phone' => ['nullable', 'string', 'regex:'.StudentInput::PHONE_PATTERN],
            'primary_guardian' => ['nullable', 'integer', 'min:0'],
            'existing_membership_id' => ['nullable', 'uuid'],
            'confirm_new' => ['nullable', 'boolean'],
            'class_id' => ['nullable', 'uuid'],
        ], [
            'name.required' => 'Nama lengkap wajib diisi.',
            'birth_date.before_or_equal' => 'Tanggal lahir tidak boleh di masa depan.',
            'start_date.before_or_equal' => 'Tanggal masuk tidak boleh di masa depan.',
            'phone.regex' => 'Nomor HP hanya angka (boleh diawali +), 8–19 digit.',
            'nisn.regex' => 'NISN harus 10 angka.',
            'student_number.regex' => 'Nomor induk hanya huruf, angka, titik, garis miring, dan tanda "-", maksimal 30 karakter.',
            'organization_id.required' => 'Pilih lembaga.',
            'guardians.max' => 'Paling banyak '.StudentInput::MAX_GUARDIANS.' orang tua/wali.',
            'guardians.*.relation.required' => 'Pilih hubungan.',
            'guardians.*.name.required' => 'Nama orang tua/wali wajib diisi.',
            'guardians.*.phone.regex' => 'Nomor HP hanya angka (boleh diawali +), 8–19 digit.',
        ], $this->attributes());

        // Lembaga tujuan: yayasan lain / ID asal-asalan → 404; di luar cakupan → 404; tanpa izin → 403.
        $organization = Organization::query()->find($request->string('organization_id')->value());

        if (! $organization instanceof Organization) {
            abort(404);
        }

        Gate::authorize(AcademicAccess::STUDENTS_MANAGE, $organization);

        // Kelas (opsional, AC-1d-b): wajib di cakupan izin kelola kelas.
        $classId = StudentInput::text($request->input('class_id'));

        if ($classId !== null) {
            $class = SchoolClass::query()->find($classId);

            if (! $class instanceof SchoolClass) {
                throw ValidationException::withMessages(['class_id' => 'Kelas tidak ditemukan.']);
            }

            Gate::authorize(AcademicAccess::CLASSES_MANAGE, $class);
        }

        $guardians = [];

        foreach ($guardiansInput as $row) {
            $guardians[] = new GuardianData(
                relation: GuardianRelation::from((string) $row['relation']),
                name: (string) StudentInput::name($row['name'] ?? null),
                gender: isset($row['gender']) && $row['gender'] !== '' ? Gender::tryFrom((string) $row['gender']) : null,
                phone: StudentInput::text($row['phone'] ?? null),
            );
        }

        $data = new NewStudentData(
            tenantId: $this->tenantContext->require()->tenantId,
            name: (string) StudentInput::name($request->input('name')),
            gender: $request->enum('gender', Gender::class),
            birthPlace: StudentInput::text($request->input('birth_place')),
            birthDate: StudentInput::text($request->input('birth_date')),
            phone: StudentInput::text($request->input('phone')),
            address: StudentInput::text($request->input('address')),
            nisn: StudentInput::text($request->input('nisn')),
            organizationId: $organization->id,
            studentNumber: StudentInput::text($request->input('student_number')),
            startDate: $request->string('start_date')->value(),
            entryType: $request->enum('entry_type', EntryType::class) ?? EntryType::New,
            guardians: $guardians,
            primaryGuardian: $guardians === [] ? null : $request->integer('primary_guardian'),
            existingMembershipId: StudentInput::text($request->input('existing_membership_id')),
            confirmNew: $request->boolean('confirm_new'),
            classId: $classId,
        );

        try {
            $created = $createStudent->handle($data);
        } catch (StudentException $exception) {
            throw ValidationException::withMessages([$exception->field => $exception->getMessage()]);
        } catch (StudentCheckFailed $exception) {
            // Semua masalah tampil sekaligus: pesan di tiap isian + kotak peringatan nama sama (bila ada).
            $request->session()->flash(self::CANDIDATES_SESSION_KEY, $exception->candidates);
            $messages = $exception->errors;
            $duplicate = $exception->duplicateMessage();

            if ($duplicate !== null) {
                $messages['duplicate'] = $duplicate;
            }

            throw ValidationException::withMessages($messages);
        }

        $message = $created->newPerson
            ? "{$created->name} berhasil didaftarkan di {$created->organizationName}."
            : "{$created->name} (orang yang sudah ada) berhasil didaftarkan di {$created->organizationName}.";

        if ($created->className !== null) {
            $message .= " Masuk kelas {$created->className}.";
        }

        if ($created->guardiansReused > 0) {
            $message .= " {$created->guardiansReused} orang tua/wali memakai data yang sudah ada (nama & HP sama).";
        }

        $request->session()->forget(self::CANDIDATES_SESSION_KEY);
        Inertia::flash('toast', ['type' => 'success', 'message' => $message]);

        // Setelah tersimpan langsung ke detail siswa (pola HR-1c).
        return to_route('academic.students.show', ['student' => $created->student->id]);
    }

    // ── Bantuan ───────────────────────────────────────────────────────────────

    /**
     * Halaman hanya untuk pemegang izin kelola siswa di setidaknya satu lembaga lembaga kerja ini.
     */
    private function authorizeAnywhere(): void
    {
        if (! $this->authorization->allows(AcademicAccess::STUDENTS_MANAGE)) {
            abort(403);
        }
    }

    /**
     * Pilihan saringan lembaga: unit & lembaga yang terlihat (semua bila tingkat Yayasan).
     *
     * @return list<array{value: string, label: string}>
     */
    private function filterOrganizationOptions(StudentVisibility $visibility): array
    {
        $query = Organization::query()
            ->whereIn('type', [OrganizationType::Lembaga->value, OrganizationType::Unit->value])
            ->orderBy('name');
        $nodeIds = $visibility->nodeIds(AcademicAccess::STUDENTS_VIEW);

        if ($nodeIds !== null) {
            $query->whereIn('id', $nodeIds);
        }

        $options = [];

        foreach ($query->get() as $organization) {
            $suffix = $organization->isActive() ? '' : ' — nonaktif';
            $options[] = ['value' => $organization->id, 'label' => $organization->name.$suffix];
        }

        return $options;
    }

    /**
     * Pilihan kelas di form Tambah siswa (AC-1d-b): per lembaga, kelas aktif di tahun ajaran berjalan
     * kalender lembaga itu, hanya bila boleh mengelola kelas di sana.
     *
     * @param  list<array{value: string, label: string}>  $organizations
     * @return array<string, list<array{value: string, label: string}>>
     */
    private function classOptions(AcademicCalendarResolver $calendars, array $organizations): array
    {
        $tenantId = $this->tenantContext->require()->tenantId;
        $options = [];

        foreach ($organizations as $organization) {
            if (! $this->authorization->allows(AcademicAccess::CLASSES_MANAGE, $organization['value'])) {
                continue;
            }

            $year = $calendars->currentYearFor($tenantId, $organization['value']);

            if ($year === null) {
                continue;
            }

            $classes = SchoolClass::query()
                ->where('organization_id', $organization['value'])
                ->where('academic_year_id', $year->id)
                ->where('is_active', true)
                ->orderBy('grade')
                ->orderBy('name')
                ->get();

            foreach ($classes as $class) {
                $options[$organization['value']][] = ['value' => $class->id, 'label' => "{$class->name} ({$year->name})"];
            }
        }

        return $options;
    }

    /**
     * Lembaga aktif yang boleh dikelola di lembaga kerja ini (bukan unit/biro).
     *
     * @return list<array{value: string, label: string}>
     */
    private function organizationOptions(): array
    {
        $organizations = Organization::query()
            ->whereIn('id', $this->authorization->visibleNodeIds(AcademicAccess::STUDENTS_MANAGE))
            ->where('type', OrganizationType::Lembaga->value)
            ->where('status', OrganizationStatus::Active->value)
            ->orderBy('name')
            ->get();

        $options = [];

        foreach ($organizations as $organization) {
            $options[] = ['value' => $organization->id, 'label' => $organization->name];
        }

        return $options;
    }

    /**
     * @param  list<Gender|EntryType|GuardianRelation|EnrollmentEndReason>  $cases
     * @return list<array{value: string, label: string}>
     */
    private function enumOptions(array $cases): array
    {
        $options = [];

        foreach ($cases as $case) {
            $options[] = ['value' => $case->value, 'label' => $case->label()];
        }

        return $options;
    }

    /**
     * Calon orang ganda dari percobaan simpan sebelumnya (hanya satu kali tampil).
     *
     * @return list<array{membership_id: string, label: string}>
     */
    private function flashedCandidates(Request $request): array
    {
        $stored = $request->session()->get(self::CANDIDATES_SESSION_KEY);
        $candidates = [];

        if (! is_array($stored)) {
            return [];
        }

        foreach ($stored as $candidate) {
            if (is_array($candidate) && is_string($candidate['membership_id'] ?? null) && Str::isUuid($candidate['membership_id']) && is_string($candidate['label'] ?? null)) {
                $candidates[] = ['membership_id' => $candidate['membership_id'], 'label' => $candidate['label']];
            }
        }

        return $candidates;
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
            'guardians.*.relation' => 'hubungan',
            'guardians.*.name' => 'nama orang tua/wali',
            'guardians.*.gender' => 'jenis kelamin orang tua/wali',
            'guardians.*.phone' => 'nomor HP orang tua/wali',
            'primary_guardian' => 'kontak utama',
        ];
    }
}
