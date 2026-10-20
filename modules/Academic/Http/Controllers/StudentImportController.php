<?php

namespace Modules\Academic\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Modules\Academic\Application\Authorization\AcademicAccess;
use Modules\Academic\Application\Import\ImportSaveException;
use Modules\Academic\Application\Import\ImportStudents;
use Modules\Academic\Application\Import\PendingStudentImport;
use Modules\Academic\Application\Import\StudentImportColumns;
use Modules\Academic\Application\Import\StudentImportReader;
use Modules\Academic\Application\Import\StudentImportTemplate;
use Modules\Academic\Application\Import\StudentImportValidator;
use Modules\Core\Application\Authorization\AuthorizationService;
use Modules\Core\Domain\Organization\Organization;
use Modules\Core\Domain\Organization\OrganizationStatus;
use Modules\Core\Domain\Organization\OrganizationType;
use Modules\Core\Domain\Tenancy\TenantContext;
use Modules\Core\Support\Import\ImportFileException;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Impor siswa dari Excel/CSV (PRD-002 AC-1e): template, unggah, pratinjau, simpan (AC-1e-b), batal.
 * Pola sama dengan impor pegawai (PRD-001 §6.2).
 *
 * Siapa: pemegang `academic.students.manage` (Operator Akademik di lembaganya, Admin Yayasan).
 * Satu file = satu lembaga, dipilih saat unggah [OWNER, 2026-10-20]: di luar cakupan → 404,
 * tanpa izin → 403. Pratinjau tidak menyimpan data siswa apa pun; Simpan menyimpan semua baris
 * sekaligus atau tidak sama sekali.
 */
final class StudentImportController
{
    /** Batas ukuran file dalam kilobyte (pola PRD-001 §6.2). */
    public const MAX_KILOBYTES = 2048;

    private const SAVE_TIME_LIMIT_SECONDS = 300;

    public function __construct(
        private readonly AuthorizationService $authorization,
        private readonly TenantContext $tenantContext,
        private readonly PendingStudentImport $store,
    ) {}

    public function index(Request $request, StudentImportReader $reader, StudentImportValidator $validator): Response
    {
        $this->authorizeAnywhere();

        $tenantId = $this->tenantId();
        $pending = $this->store->current($request->session(), $tenantId);
        $pendingProps = null;
        $preview = null;

        if ($pending !== null) {
            $organization = Organization::query()->find($pending['organization_id']);

            // Lembaga dinonaktifkan atau izin dicabut sejak diunggah: file dibuang.
            if (! $organization instanceof Organization || ! $organization->isActive() || ! $this->authorization->allows(AcademicAccess::STUDENTS_MANAGE, $organization->id)) {
                $this->store->forget($request->session());
            } else {
                try {
                    // Selalu diperiksa ulang: siswa, NISN, atau kelas bisa berubah sejak diunggah.
                    $preview = $validator->validate(
                        $tenantId,
                        $organization,
                        $this->authorization->allows(AcademicAccess::CLASSES_MANAGE, $organization->id),
                        $reader->read($pending['path'], $pending['extension']),
                    )->toArray();
                    $pendingProps = [
                        'name' => $pending['name'],
                        'organization' => ['value' => $organization->id, 'label' => $organization->name],
                    ];
                } catch (ImportFileException) {
                    $this->store->forget($request->session());
                }
            }
        }

        return Inertia::render('academic/students/import', [
            'pending' => $pendingProps,
            'preview' => $preview,
            'organizations' => $this->organizationOptions(),
            'columns' => StudentImportColumns::all(),
            'limits' => [
                'rows' => StudentImportReader::MAX_ROWS,
                'kilobytes' => self::MAX_KILOBYTES,
            ],
        ]);
    }

    public function template(Request $request, StudentImportTemplate $template): BinaryFileResponse
    {
        $organization = $this->targetOrganization($request->query('lembaga'));

        $path = tempnam(sys_get_temp_dir(), 'educore-template-');

        if ($path === false) {
            abort(500, 'Template tidak bisa dibuat.');
        }

        $template->write($this->tenantId(), $organization, $path);

        return response()
            ->download($path, StudentImportTemplate::fileName($organization), [
                'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            ])
            ->deleteFileAfterSend();
    }

    public function upload(Request $request, StudentImportReader $reader): RedirectResponse
    {
        $this->authorizeAnywhere();

        $request->validate([
            'organization_id' => ['required', 'uuid'],
            'file' => ['required', 'file', 'max:'.self::MAX_KILOBYTES, 'extensions:xlsx,csv'],
        ], [
            'organization_id.required' => 'Pilih lembaga tujuan.',
            'file.required' => 'Pilih file .xlsx atau .csv.',
            'file.max' => 'Ukuran file maksimal 2 MB.',
            'file.extensions' => 'File harus berformat .xlsx atau .csv.',
            'file.uploaded' => 'File gagal diunggah. Coba lagi; ukuran maksimal 2 MB.',
        ], ['file' => 'file', 'organization_id' => 'lembaga']);

        $organization = $this->targetOrganization($request->input('organization_id'));
        $file = $request->file('file');

        if (! $file instanceof UploadedFile) {
            throw ValidationException::withMessages(['file' => 'Pilih satu file .xlsx atau .csv.']);
        }

        $extension = strtolower($file->getClientOriginalExtension()) === 'xlsx' ? 'xlsx' : 'csv';

        // File yang tidak bisa dibaca sama sekali langsung ditolak dan tidak disimpan.
        try {
            $reader->read($file->getRealPath() ?: $file->getPathname(), $extension);
        } catch (ImportFileException $exception) {
            throw ValidationException::withMessages(['file' => $exception->getMessage()]);
        }

        $this->store->put($request->session(), $file, $this->tenantId(), $organization->id);

        return redirect()->route('academic.students.import');
    }

    public function save(Request $request, ImportStudents $importStudents): RedirectResponse
    {
        $this->authorizeAnywhere();

        $pending = $this->store->current($request->session(), $this->tenantId());

        if ($pending === null) {
            throw ValidationException::withMessages(['save' => 'Belum ada file impor. Unggah file terlebih dahulu.']);
        }

        // Lembaga tujuan diperiksa ulang: izin bisa dicabut sejak diunggah.
        $organization = $this->targetOrganization($pending['organization_id']);

        // Impor ratusan siswa butuh waktu lebih lama dari batas bawaan PHP (30 detik).
        set_time_limit(self::SAVE_TIME_LIMIT_SECONDS);

        try {
            $result = $importStudents->handle(
                $this->tenantId(),
                $organization,
                $this->authorization->allows(AcademicAccess::CLASSES_MANAGE, $organization->id),
                $pending['path'],
                $pending['extension'],
                $request->boolean('confirm_warnings'),
            );
        } catch (ImportFileException|ImportSaveException $exception) {
            throw ValidationException::withMessages(['save' => $exception->getMessage()]);
        }

        $this->store->forget($request->session());

        $message = "{$result['saved']} siswa berhasil diimpor ke {$organization->name}.";

        if ($result['existing'] > 0) {
            $message .= " {$result['existing']} di antaranya siswa yang sudah tercatat (hanya didaftarkan).";
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => $message]);

        return redirect()->route('academic.students.index', ['lembaga' => $organization->id]);
    }

    public function cancel(Request $request): RedirectResponse
    {
        $this->authorizeAnywhere();

        $this->store->forget($request->session());

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Impor dibatalkan. File sudah dihapus.']);

        return redirect()->route('academic.students.import');
    }

    // ── Bantuan ───────────────────────────────────────────────────────────────

    private function authorizeAnywhere(): void
    {
        if (! $this->authorization->allows(AcademicAccess::STUDENTS_MANAGE)) {
            abort(403);
        }
    }

    /**
     * Lembaga tujuan impor: lembaga aktif di cakupan izin kelola siswa (Gate: luar cakupan 404,
     * tanpa izin 403).
     */
    private function targetOrganization(mixed $organizationId): Organization
    {
        $this->authorizeAnywhere();

        $organization = is_string($organizationId) && Str::isUuid($organizationId)
            ? Organization::query()->find($organizationId)
            : null;

        if (! $organization instanceof Organization) {
            abort(404);
        }

        Gate::authorize(AcademicAccess::STUDENTS_MANAGE, $organization);

        if ($organization->type !== OrganizationType::Lembaga || ! $organization->isActive()) {
            throw ValidationException::withMessages(['organization_id' => 'Pilih lembaga yang aktif (bukan unit atau biro).']);
        }

        return $organization;
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

    private function tenantId(): string
    {
        return $this->tenantContext->require()->tenantId;
    }
}
