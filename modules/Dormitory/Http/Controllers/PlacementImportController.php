<?php

namespace Modules\Dormitory\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Modules\Core\Support\ExecutionTime;
use Modules\Core\Support\Import\ImportFileException;
use Modules\Dormitory\Application\Authorization\DormitoryAccess;
use Modules\Dormitory\Application\Import\PendingPlacementImport;
use Modules\Dormitory\Application\Import\PlacementImportColumns;
use Modules\Dormitory\Application\Import\PlacementImporter;
use Modules\Dormitory\Application\Import\PlacementImportReader;
use Modules\Dormitory\Application\Import\PlacementImportTemplate;
use Modules\Dormitory\Domain\Facility\Dormitory;
use Modules\Dormitory\Http\Controllers\Concerns\HandlesFacilityActions;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Impor penempatan santri dari Excel/CSV (PRD-008 §20): template, unggah, pratinjau, simpan, batal. Pola sama dengan
 * impor siswa (PRD-002 AC-1e). Butuh `dormitory.residents.manage` di pemilik asrama (Gate: luar cakupan 404, tanpa
 * izin 403). Pratinjau tidak menyimpan apa pun; Simpan menyimpan semua baris atau tidak sama sekali.
 */
final class PlacementImportController
{
    use HandlesFacilityActions;

    /** Batas ukuran file dalam kilobyte (pola impor siswa/pegawai). */
    public const MAX_KILOBYTES = 2048;

    /** Seribu baris diperiksa dengan aturan penempatan penuh; lebih lama dari batas bawaan PHP. */
    private const TIME_LIMIT_SECONDS = 300;

    public function __construct(
        private readonly PendingPlacementImport $store,
    ) {}

    public function index(Request $request, string $dormitory, PlacementImportReader $reader, PlacementImporter $importer): Response
    {
        $target = $this->dormitory($dormitory);
        $pending = $this->store->current($request->session(), $this->tenantId());
        $pendingProps = null;
        $preview = null;

        // File milik asrama lain di session tidak ditampilkan di sini (tetap tersimpan sampai diganti/kedaluwarsa).
        if ($pending !== null && $pending['dormitory_id'] === $target->id) {
            try {
                ExecutionTime::atLeast(self::TIME_LIMIT_SECONDS);
                // Selalu diperiksa ulang: kamar, bed, dan penempatan bisa berubah sejak diunggah.
                $preview = $importer->preview($this->tenantId(), $target, $reader->read($pending['path'], $pending['extension']), $this->actorId());
                $pendingProps = ['name' => $pending['name']];
            } catch (ImportFileException) {
                $this->store->forget($request->session());
            }
        }

        return Inertia::render('dormitory/import', [
            'dormitory' => ['id' => $target->id, 'code' => $target->code, 'name' => $target->name],
            'pending' => $pendingProps,
            'preview' => $preview,
            'columns' => PlacementImportColumns::all(),
            'limits' => ['rows' => PlacementImportReader::MAX_ROWS, 'kilobytes' => self::MAX_KILOBYTES],
        ]);
    }

    public function template(string $dormitory, PlacementImportTemplate $template): BinaryFileResponse
    {
        $target = $this->dormitory($dormitory);
        $path = tempnam(sys_get_temp_dir(), 'educore-template-');

        if ($path === false) {
            abort(500, 'Template tidak bisa dibuat.');
        }

        $template->write($target, $path);

        return response()
            ->download($path, PlacementImportTemplate::fileName($target), [
                'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            ])
            ->deleteFileAfterSend();
    }

    public function upload(Request $request, string $dormitory, PlacementImportReader $reader): RedirectResponse
    {
        $target = $this->dormitory($dormitory);

        $request->validate([
            'file' => ['required', 'file', 'max:'.self::MAX_KILOBYTES, 'extensions:xlsx,csv'],
        ], [
            'file.required' => 'Pilih file .xlsx atau .csv.',
            'file.max' => 'Ukuran file maksimal 2 MB.',
            'file.extensions' => 'File harus berformat .xlsx atau .csv.',
            'file.uploaded' => 'File gagal diunggah. Coba lagi; ukuran maksimal 2 MB.',
        ]);

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

        $this->store->put($request->session(), $file, $this->tenantId(), $target->id);

        return redirect()->route('dormitory.import.index', ['dormitory' => $target->id]);
    }

    public function save(Request $request, string $dormitory, PlacementImportReader $reader, PlacementImporter $importer): RedirectResponse
    {
        $target = $this->dormitory($dormitory);
        $pending = $this->store->current($request->session(), $this->tenantId());

        if ($pending === null || $pending['dormitory_id'] !== $target->id) {
            throw ValidationException::withMessages(['save' => 'Belum ada file impor untuk asrama ini. Unggah file terlebih dahulu.']);
        }

        ExecutionTime::atLeast(self::TIME_LIMIT_SECONDS);

        try {
            $result = $importer->save($this->tenantId(), $target, $reader->read($pending['path'], $pending['extension']), $this->actorId());
        } catch (ImportFileException $exception) {
            throw ValidationException::withMessages(['save' => $exception->getMessage()]);
        }

        if ($result['errors'] > 0) {
            throw ValidationException::withMessages(['save' => "Tidak ada yang disimpan: {$result['errors']} baris salah. Perbaiki file lalu unggah ulang."]);
        }

        $this->store->forget($request->session());
        Inertia::flash('toast', ['type' => 'success', 'message' => "{$result['ready']} penempatan berhasil diimpor ke {$target->name}."]);

        return redirect()->to("/asrama/{$target->id}?tab=penghuni");
    }

    public function cancel(Request $request, string $dormitory): RedirectResponse
    {
        $target = $this->dormitory($dormitory);
        $this->store->forget($request->session());
        Inertia::flash('toast', ['type' => 'success', 'message' => 'Impor dibatalkan. File sudah dihapus.']);

        return redirect()->route('dormitory.import.index', ['dormitory' => $target->id]);
    }

    private function dormitory(string $dormitoryId): Dormitory
    {
        $dormitory = Str::isUuid($dormitoryId) ? Dormitory::query()->find($dormitoryId) : null;

        if (! $dormitory instanceof Dormitory) {
            abort(404);
        }

        Gate::authorize(DormitoryAccess::RESIDENTS_MANAGE, $dormitory);

        return $dormitory;
    }
}
