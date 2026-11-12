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
use Modules\Core\Support\BusinessClock;
use Modules\HR\Application\Authorization\HrAccess;
use Modules\HR\Application\Employee\EmployeeVisibility;
use Modules\HR\Application\Training\ManageTrainings;
use Modules\HR\Application\Training\TrainingRecapExport;
use Modules\HR\Application\Training\TrainingRecords;
use Modules\HR\Domain\Training\Exceptions\TrainingException;
use Modules\HR\Domain\Training\TrainingKind;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Riwayat pelatihan pegawai (PRD-001 §19.1, HR-8a; OD-HR-16).
 *
 * - Halaman Pelatihan pegawai, catat, terima, tolak, batalkan: Admin SDM / Admin Yayasan (`hr.employees.manage`,
 *   tingkat Yayasan); selain itu 403.
 * - Unduh lampiran: `hr.employees.view_personal` sesuai cakupan (sama dengan Dokumen & SK); di luar cakupan 404.
 */
final class TrainingController
{
    public function __construct(
        private readonly AuthorizationService $authorization,
        private readonly TenantContext $tenantContext,
    ) {}

    public function index(Request $request, TrainingRecords $records): Response
    {
        $this->authorizeManage();
        $tenantId = $this->tenantContext->require()->tenantId;
        $years = $records->years($tenantId);
        $year = in_array($request->integer('tahun'), $years, true) ? $request->integer('tahun') : $years[0];

        return Inertia::render('hr/trainings/index', [
            'pending' => $records->pending($tenantId),
            'recap' => $records->recap($tenantId, $year),
            'year' => $year,
            'years' => $years,
        ]);
    }

    public function export(Request $request, TrainingRecords $records, TrainingRecapExport $export): BinaryFileResponse
    {
        $this->authorizeManage();
        $tenantId = $this->tenantContext->require()->tenantId;
        $years = $records->years($tenantId);
        $year = in_array($request->integer('tahun'), $years, true) ? $request->integer('tahun') : $years[0];
        $path = tempnam(sys_get_temp_dir(), 'educore-pelatihan-');

        if ($path === false) {
            abort(500, 'File unduhan tidak bisa dibuat.');
        }

        $export->write($records->recap($tenantId, $year), "Rekap pelatihan pegawai tahun {$year} (per ".BusinessClock::todayString().')', $path);

        return response()
            ->download($path, "rekap-pelatihan-{$year}.xlsx", ['Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'])
            ->deleteFileAfterSend();
    }

    public function store(Request $request, string $employee, ManageTrainings $trainings): RedirectResponse
    {
        $this->authorizeManage();
        self::validateFile($request);
        $context = $this->tenantContext->require();
        $file = $request->file('file');

        $this->attempt(fn () => $trainings->record($context->tenantId, $employee, self::input($request), $file instanceof UploadedFile ? $file : null, $context->membershipId, bySelf: false));

        return $this->done('Riwayat pelatihan dicatat.');
    }

    public function verify(string $employee, string $training, ManageTrainings $trainings): RedirectResponse
    {
        $this->authorizeManage();
        $context = $this->tenantContext->require();
        $this->attempt(fn () => $trainings->verify($context->tenantId, $employee, $training, $context->membershipId));

        return $this->done('Pengajuan pelatihan diterima.');
    }

    public function reject(Request $request, string $employee, string $training, ManageTrainings $trainings): RedirectResponse
    {
        $this->authorizeManage();
        $context = $this->tenantContext->require();
        $this->attempt(fn () => $trainings->reject($context->tenantId, $employee, $training, $request->string('reason')->value(), $context->membershipId));

        return $this->done('Pengajuan pelatihan ditolak. Alasannya terlihat oleh pegawai.');
    }

    public function cancel(Request $request, string $employee, string $training, ManageTrainings $trainings): RedirectResponse
    {
        $this->authorizeManage();
        $context = $this->tenantContext->require();
        $this->attempt(fn () => $trainings->cancel($context->tenantId, $employee, $training, $request->string('reason')->value(), $context->membershipId));

        return $this->done('Catatan pelatihan dibatalkan. Catatannya tetap tersimpan sebagai riwayat.');
    }

    public function download(string $employee, string $training, EmployeeVisibility $visibility, ManageTrainings $trainings): BinaryFileResponse
    {
        if (! $visibility->canSee($employee, HrAccess::EMPLOYEES_VIEW_PERSONAL)) {
            abort($visibility->canSee($employee, HrAccess::EMPLOYEES_VIEW) ? 403 : 404);
        }

        try {
            $file = $trainings->attachment($this->tenantContext->require()->tenantId, $employee, $training);
        } catch (TrainingException) {
            abort(404);
        }

        return DisciplineController::send($file);
    }

    /**
     * @return list<array{value: string, label: string}>
     */
    public static function kindOptions(): array
    {
        return array_map(fn (TrainingKind $kind): array => ['value' => $kind->value, 'label' => $kind->label()], TrainingKind::cases());
    }

    /**
     * Bahan form catat/ajukan pelatihan.
     *
     * @return array{today: string, kinds: list<array{value: string, label: string}>, titleMax: int, organizerMax: int, certificateMax: int, reasonMax: int, fileMaxMb: int}
     */
    public static function formOptions(): array
    {
        return [
            'today' => BusinessClock::todayString(),
            'kinds' => self::kindOptions(),
            'titleMax' => ManageTrainings::TITLE_MAX,
            'organizerMax' => ManageTrainings::ORGANIZER_MAX,
            'certificateMax' => ManageTrainings::CERTIFICATE_MAX,
            'reasonMax' => ManageTrainings::REASON_MAX,
            'fileMaxMb' => intdiv(ManageTrainings::FILE_MAX_KILOBYTES, 1024),
        ];
    }

    public static function validateFile(Request $request): void
    {
        $request->validate([
            'file' => ['nullable', 'file', 'max:'.ManageTrainings::FILE_MAX_KILOBYTES, 'mimes:pdf,jpg,jpeg,png'],
        ], [
            'file.mimes' => 'Lampiran harus berupa PDF, JPG, atau PNG.',
            'file.max' => 'Ukuran lampiran maksimal '.(ManageTrainings::FILE_MAX_KILOBYTES / 1024).' MB.',
            'file.uploaded' => 'Lampiran gagal diunggah. Pastikan ukurannya maksimal '.(ManageTrainings::FILE_MAX_KILOBYTES / 1024).' MB.',
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public static function input(Request $request): array
    {
        return $request->only(['kind', 'title', 'organizer', 'start_date', 'end_date', 'hours', 'certificate_number', 'valid_until']);
    }

    private function authorizeManage(): void
    {
        $this->authorization->inspectTarget(HrAccess::EMPLOYEES_MANAGE, null, null)->authorize();
    }

    /**
     * @param  callable(): mixed  $action
     */
    private function attempt(callable $action): void
    {
        try {
            $action();
        } catch (TrainingException $exception) {
            throw ValidationException::withMessages([$exception->field => $exception->getMessage()]);
        }
    }

    private function done(string $message): RedirectResponse
    {
        Inertia::flash('toast', ['type' => 'success', 'message' => $message]);

        return back();
    }
}
