<?php

namespace Modules\HR\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Modules\Core\Application\Authorization\AuthorizationService;
use Modules\Core\Domain\Tenancy\TenantContext;
use Modules\Core\Support\ExecutionTime;
use Modules\HR\Application\Authorization\HrAccess;
use Modules\HR\Application\Import\EmployeeImportColumns;
use Modules\HR\Application\Import\EmployeeImportReader;
use Modules\HR\Application\Import\EmployeeImportTemplate;
use Modules\HR\Application\Import\EmployeeImportValidator;
use Modules\HR\Application\Import\ImportEmployees;
use Modules\HR\Application\Import\ImportFileException;
use Modules\HR\Application\Import\ImportSaveException;
use Modules\HR\Application\Import\PendingImportStore;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Impor pegawai dari Excel/CSV (HR-1e, PRD-001 §6.2): template, unggah, pratinjau, simpan, batal.
 *
 * Hanya Admin SDM / Admin Yayasan di lembaga kerja Seluruh Yayasan (`hr.employees.manage`,
 * OD-HR-02): selain itu 403. Pratinjau tidak menyimpan data pegawai apa pun; Simpan menyimpan
 * semua baris sekaligus atau tidak sama sekali (OD-HR-05).
 */
final class EmployeeImportController
{
    /** Batas ukuran file dalam kilobyte (PRD-001 §6.2). */
    public const MAX_KILOBYTES = 2048;

    private const SAVE_TIME_LIMIT_SECONDS = 300;

    public function __construct(
        private readonly AuthorizationService $authorization,
        private readonly TenantContext $tenantContext,
        private readonly PendingImportStore $store,
    ) {}

    public function index(Request $request, EmployeeImportReader $reader, EmployeeImportValidator $validator): Response
    {
        $this->authorize();

        $tenantId = $this->tenantId();
        $pending = $this->store->current($request->session(), $tenantId);
        $preview = null;

        if ($pending !== null) {
            try {
                // Selalu diperiksa ulang: katalog, lembaga, atau pegawai bisa berubah sejak diunggah.
                $preview = $validator->validate($tenantId, $reader->read($pending['path'], $pending['extension']))->toArray();
            } catch (ImportFileException) {
                $this->store->forget($request->session());
                $pending = null;
            }
        }

        return Inertia::render('hr/employees/import', [
            'pending' => $pending === null ? null : ['name' => $pending['name']],
            'preview' => $preview,
            'columns' => EmployeeImportColumns::all(),
            'limits' => [
                'rows' => EmployeeImportReader::MAX_ROWS,
                'kilobytes' => self::MAX_KILOBYTES,
            ],
        ]);
    }

    public function template(EmployeeImportTemplate $template): BinaryFileResponse
    {
        $this->authorize();

        $path = tempnam(sys_get_temp_dir(), 'educore-template-');

        if ($path === false) {
            abort(500, 'Template tidak bisa dibuat.');
        }

        $template->write($this->tenantId(), $path);

        return response()
            ->download($path, EmployeeImportTemplate::FILE_NAME, [
                'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            ])
            ->deleteFileAfterSend();
    }

    public function upload(Request $request, EmployeeImportReader $reader): RedirectResponse
    {
        $this->authorize();

        $request->validate([
            'file' => ['required', 'file', 'max:'.self::MAX_KILOBYTES, 'extensions:xlsx,csv'],
        ], [
            'file.required' => 'Pilih file .xlsx atau .csv.',
            'file.max' => 'Ukuran file maksimal 2 MB.',
            'file.extensions' => 'File harus berformat .xlsx atau .csv.',
            'file.uploaded' => 'File gagal diunggah. Coba lagi; ukuran maksimal 2 MB.',
        ], ['file' => 'file']);

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

        $this->store->put($request->session(), $file, $this->tenantId());

        return redirect()->route('hr.employees.import');
    }

    public function save(Request $request, ImportEmployees $importEmployees): RedirectResponse
    {
        $this->authorize();

        $pending = $this->store->current($request->session(), $this->tenantId());

        if ($pending === null) {
            throw ValidationException::withMessages(['save' => 'Belum ada file impor. Unggah file terlebih dahulu.']);
        }

        // Impor ratusan pegawai butuh waktu lebih lama dari batas bawaan PHP (30 detik).
        ExecutionTime::atLeast(self::SAVE_TIME_LIMIT_SECONDS);

        try {
            $count = $importEmployees->handle(
                $this->tenantId(),
                $pending['path'],
                $pending['extension'],
                $request->boolean('confirm_warnings'),
            );
        } catch (ImportFileException|ImportSaveException $exception) {
            throw ValidationException::withMessages(['save' => $exception->getMessage()]);
        }

        $this->store->forget($request->session());

        Inertia::flash('toast', ['type' => 'success', 'message' => "{$count} pegawai berhasil diimpor."]);

        return redirect()->route('hr.employees.index');
    }

    public function cancel(Request $request): RedirectResponse
    {
        $this->authorize();

        $this->store->forget($request->session());

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Impor dibatalkan. File sudah dihapus.']);

        return redirect()->route('hr.employees.import');
    }

    private function authorize(): void
    {
        $this->authorization->inspectTarget(HrAccess::EMPLOYEES_MANAGE, null, null)->authorize();
    }

    private function tenantId(): string
    {
        return $this->tenantContext->require()->tenantId;
    }
}
