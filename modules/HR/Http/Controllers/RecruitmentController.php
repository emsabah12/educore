<?php

namespace Modules\HR\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Modules\Core\Application\Authorization\AuthorizationService;
use Modules\Core\Domain\Organization\Organization;
use Modules\Core\Domain\Organization\OrganizationStatus;
use Modules\Core\Domain\Person\Gender;
use Modules\Core\Domain\Tenancy\TenantContext;
use Modules\Core\Support\BusinessClock;
use Modules\HR\Application\Authorization\HrAccess;
use Modules\HR\Application\Recruitment\ManageApplicants;
use Modules\HR\Application\Recruitment\ManageVacancies;
use Modules\HR\Application\Recruitment\RecruitmentExport;
use Modules\HR\Application\Recruitment\RecruitmentRecords;
use Modules\HR\Domain\Catalog\CatalogKind;
use Modules\HR\Domain\Employee\EducationLevel;
use Modules\HR\Domain\Recruitment\ApplicantStage;
use Modules\HR\Domain\Recruitment\Exceptions\RecruitmentException;
use Modules\HR\Domain\Recruitment\VacancyStatus;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Rekrutmen (PRD-001 §20.1, HR-9a; OD-HR-19, OD-HR-20).
 *
 * - Daftar & detail lowongan, unduh berkas, catatan wawancara: `hr.recruitment.view` di cakupan lembaga tujuan
 *   (Kepala Lembaga); lowongan di luar cakupan → 404.
 * - Lowongan, pelamar, tahap seleksi: `hr.recruitment.manage` tingkat Yayasan (Admin SDM / Admin Yayasan).
 */
final class RecruitmentController
{
    public function __construct(
        private readonly AuthorizationService $authorization,
        private readonly TenantContext $tenantContext,
    ) {}

    public function index(Request $request, RecruitmentRecords $records): Response
    {
        Gate::authorize(HrAccess::RECRUITMENT_VIEW);

        $scope = $this->scope();
        $organizations = $this->organizationOptions($scope);
        $organization = $request->string('lembaga')->value();
        $organization = in_array($organization, array_column($organizations, 'value'), true) ? $organization : null;
        $status = VacancyStatus::tryFrom($request->string('status')->value())?->value;

        return Inertia::render('hr/recruitment/index', [
            'vacancies' => $records->vacancies($this->tenantContext->require()->tenantId, $scope, $status, $organization),
            'filters' => ['status' => $status, 'lembaga' => $organization],
            'canManage' => $this->canManage(),
            'options' => $this->vacancyOptions($organizations),
            'limits' => self::limits(),
        ]);
    }

    public function show(string $vacancy, RecruitmentRecords $records): Response
    {
        Gate::authorize(HrAccess::RECRUITMENT_VIEW);
        $tenantId = $this->tenantContext->require()->tenantId;
        $row = $records->vacancy($tenantId, $vacancy, $this->scope()) ?? abort(404);

        return Inertia::render('hr/recruitment/show', [
            'vacancy' => $row,
            'applicants' => $records->applicants($tenantId, $row['id']),
            'canManage' => $this->canManage(),
            // Jadikan pegawai (HR-9b) butuh juga izin tambah pegawai (OD-HR-02).
            'canHire' => $this->canManage() && $this->authorization->inspectTarget(HrAccess::EMPLOYEES_MANAGE, null, null)->allowed(),
            'options' => $this->vacancyOptions($this->organizationOptions($this->scope())) + [
                'genders' => array_map(fn (Gender $gender): array => ['value' => $gender->value, 'label' => $gender->label()], Gender::cases()),
                'educationLevels' => array_map(fn (EducationLevel $level): array => ['value' => $level->value, 'label' => $level->label()], EducationLevel::cases()),
                'stages' => array_map(fn (ApplicantStage $stage): array => ['value' => $stage->value, 'label' => $stage->label()], ApplicantStage::cases()),
            ],
            'limits' => self::limits(),
        ]);
    }

    public function storeVacancy(Request $request, ManageVacancies $vacancies): RedirectResponse
    {
        $this->authorizeManage();
        $context = $this->tenantContext->require();
        $id = '';

        $this->attempt(function () use ($request, $vacancies, $context, &$id): void {
            $id = $vacancies->create($context->tenantId, self::vacancyInput($request), $context->membershipId);
        });

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Lowongan dibuat sebagai Draf. Buka lowongan untuk mulai mencatat pelamar.']);

        return redirect()->route('hr.recruitment.show', ['vacancy' => $id]);
    }

    public function updateVacancy(Request $request, string $vacancy, ManageVacancies $vacancies): RedirectResponse
    {
        $this->authorizeManage();
        $this->attempt(fn () => $vacancies->update($this->tenantContext->require()->tenantId, $vacancy, self::vacancyInput($request)));

        return $this->done('Lowongan diubah.');
    }

    public function openVacancy(string $vacancy, ManageVacancies $vacancies): RedirectResponse
    {
        $this->authorizeManage();
        $this->attempt(fn () => $vacancies->open($this->tenantContext->require()->tenantId, $vacancy));

        return $this->done('Lowongan dibuka.');
    }

    public function closeVacancy(string $vacancy, ManageVacancies $vacancies): RedirectResponse
    {
        $this->authorizeManage();
        $this->attempt(fn () => $vacancies->close($this->tenantContext->require()->tenantId, $vacancy));

        return $this->done('Lowongan ditutup. Seleksi pelamar yang sudah tercatat tetap bisa dilanjutkan.');
    }

    public function destroyVacancy(string $vacancy, ManageVacancies $vacancies): RedirectResponse
    {
        $this->authorizeManage();
        $this->attempt(fn () => $vacancies->delete($this->tenantContext->require()->tenantId, $vacancy));

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Lowongan dihapus.']);

        return redirect()->route('hr.recruitment.index');
    }

    public function storeApplicant(Request $request, string $vacancy, ManageApplicants $applicants): RedirectResponse
    {
        $this->authorizeManage();
        self::validateFile($request);
        $context = $this->tenantContext->require();
        $file = $request->file('file');

        $this->attempt(fn () => $applicants->add($context->tenantId, $vacancy, self::applicantInput($request), $file instanceof UploadedFile ? $file : null, $context->membershipId));

        return $this->done('Pelamar ditambahkan ke tahap Seleksi berkas.');
    }

    public function updateApplicant(Request $request, string $vacancy, string $applicant, ManageApplicants $applicants): RedirectResponse
    {
        $this->authorizeManage();
        self::validateFile($request);
        $file = $request->file('file');

        $this->attempt(fn () => $applicants->update($this->tenantContext->require()->tenantId, $vacancy, $applicant, self::applicantInput($request), $file instanceof UploadedFile ? $file : null));

        return $this->done('Data pelamar diubah.');
    }

    public function step(Request $request, string $vacancy, string $applicant, RecruitmentRecords $records, ManageApplicants $applicants): RedirectResponse
    {
        Gate::authorize(HrAccess::RECRUITMENT_VIEW);
        $context = $this->tenantContext->require();
        $records->vacancy($context->tenantId, $vacancy, $this->scope()) ?? abort(404);

        $this->attempt(fn () => $applicants->step(
            $context->tenantId,
            $vacancy,
            $applicant,
            $request->string('action')->value(),
            $request->string('score')->value(),
            $request->string('note')->value(),
            $context->membershipId,
            $this->canManage(),
        ));

        return $this->done('Tahap seleksi dicatat.');
    }

    public function download(string $vacancy, string $applicant, RecruitmentRecords $records, ManageApplicants $applicants): BinaryFileResponse
    {
        Gate::authorize(HrAccess::RECRUITMENT_VIEW);
        $tenantId = $this->tenantContext->require()->tenantId;
        $records->vacancy($tenantId, $vacancy, $this->scope()) ?? abort(404);

        try {
            $file = $applicants->attachment($tenantId, $vacancy, $applicant);
        } catch (RecruitmentException) {
            abort(404);
        }

        return DisciplineController::send($file);
    }

    public function export(string $vacancy, RecruitmentRecords $records, RecruitmentExport $export): BinaryFileResponse
    {
        $this->authorizeManage();
        $tenantId = $this->tenantContext->require()->tenantId;
        $row = $records->vacancy($tenantId, $vacancy, null) ?? abort(404);
        $path = tempnam(sys_get_temp_dir(), 'educore-rekrutmen-');

        if ($path === false) {
            abort(500, 'File unduhan tidak bisa dibuat.');
        }

        $export->write($row, $records->applicants($tenantId, $row['id']), BusinessClock::todayString(), $path);
        $slug = trim((string) preg_replace('/[^a-z0-9]+/', '-', mb_strtolower($row['title'])), '-');

        return response()
            ->download($path, 'rekap-lowongan-'.($slug === '' ? 'lowongan' : $slug).'.xlsx', ['Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'])
            ->deleteFileAfterSend();
    }

    /**
     * @return array<string, int>
     */
    private static function limits(): array
    {
        return [
            'title' => ManageVacancies::TITLE_MAX,
            'requirements' => ManageVacancies::REQUIREMENTS_MAX,
            'openings' => ManageVacancies::OPENINGS_MAX,
            'name' => ManageApplicants::NAME_MAX,
            'email' => ManageApplicants::EMAIL_MAX,
            'major' => ManageApplicants::MAJOR_MAX,
            'note' => ManageApplicants::NOTE_MAX,
            'reason' => ManageApplicants::REASON_MAX,
            'fileMaxMb' => ManageApplicants::FILE_MAX_KILOBYTES / 1024,
        ];
    }

    /**
     * @return array{title: string, organization_id: string, position_id: string, openings: string, closes_on: string, requirements: string}
     */
    private static function vacancyInput(Request $request): array
    {
        return [
            'title' => $request->string('title')->value(),
            'organization_id' => $request->string('organization_id')->value(),
            'position_id' => $request->string('position_id')->value(),
            'openings' => $request->string('openings')->value(),
            'closes_on' => $request->string('closes_on')->value(),
            'requirements' => $request->string('requirements')->value(),
        ];
    }

    /**
     * @return array{name: string, gender: string, phone: string, email: string, education_level: string, education_major: string, note: string}
     */
    private static function applicantInput(Request $request): array
    {
        return [
            'name' => $request->string('name')->value(),
            'gender' => $request->string('gender')->value(),
            'phone' => $request->string('phone')->value(),
            'email' => $request->string('email')->value(),
            'education_level' => $request->string('education_level')->value(),
            'education_major' => $request->string('education_major')->value(),
            'note' => $request->string('note')->value(),
        ];
    }

    private static function validateFile(Request $request): void
    {
        $max = ManageApplicants::FILE_MAX_KILOBYTES;

        $request->validate([
            'file' => ['nullable', 'file', 'max:'.$max, 'mimes:pdf,jpg,jpeg,png'],
        ], [
            'file.mimes' => 'Berkas harus berupa PDF, JPG, atau PNG.',
            'file.max' => 'Ukuran berkas maksimal '.($max / 1024).' MB.',
            'file.uploaded' => 'Berkas gagal diunggah. Pastikan ukurannya maksimal '.($max / 1024).' MB.',
        ]);
    }

    private function canManage(): bool
    {
        return $this->authorization->inspectTarget(HrAccess::RECRUITMENT_MANAGE, null, null)->allowed();
    }

    private function authorizeManage(): void
    {
        $this->authorization->inspectTarget(HrAccess::RECRUITMENT_MANAGE, null, null)->authorize();
    }

    /**
     * Lembaga tujuan yang boleh dilihat; null = semua (tingkat Yayasan).
     *
     * @return list<string>|null
     */
    private function scope(): ?array
    {
        if ($this->authorization->inspectTarget(HrAccess::RECRUITMENT_VIEW, null, null)->allowed()) {
            return null;
        }

        return $this->authorization->visibleNodeIds(HrAccess::RECRUITMENT_VIEW);
    }

    /**
     * @param  list<string>|null  $scope
     * @return list<array{value: string, label: string}>
     */
    private function organizationOptions(?array $scope): array
    {
        $query = Organization::query()->where('status', OrganizationStatus::Active->value)->orderBy('code');

        if ($scope !== null) {
            $query->whereIn('id', $scope);
        }

        $options = [];

        foreach ($query->get() as $organization) {
            $options[] = ['value' => (string) $organization->id, 'label' => (string) $organization->name];
        }

        return $options;
    }

    /**
     * @param  list<array{value: string, label: string}>  $organizations
     * @return array{organizations: list<array{value: string, label: string}>, positions: list<array{value: string, label: string}>, statuses: list<array{value: string, label: string}>}
     */
    private function vacancyOptions(array $organizations): array
    {
        $positions = [];

        foreach (CatalogKind::Position->query()->where('is_active', true)->orderBy('name')->get() as $position) {
            $positions[] = ['value' => (string) $position->id, 'label' => (string) $position->name];
        }

        return [
            'organizations' => $organizations,
            'positions' => $positions,
            'statuses' => array_map(fn (VacancyStatus $status): array => ['value' => $status->value, 'label' => $status->label()], VacancyStatus::cases()),
        ];
    }

    /**
     * @param  callable(): mixed  $action
     */
    private function attempt(callable $action): void
    {
        try {
            $action();
        } catch (RecruitmentException $exception) {
            throw ValidationException::withMessages([$exception->field => $exception->getMessage()]);
        }
    }

    private function done(string $message): RedirectResponse
    {
        Inertia::flash('toast', ['type' => 'success', 'message' => $message]);

        return back();
    }
}
