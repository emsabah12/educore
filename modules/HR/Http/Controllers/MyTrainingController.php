<?php

namespace Modules\HR\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Modules\Core\Domain\Tenancy\TenantContext;
use Modules\HR\Application\Training\ManageTrainings;
use Modules\HR\Application\Training\TrainingRecords;
use Modules\HR\Contracts\EmployeeLookup;
use Modules\HR\Domain\Training\Exceptions\TrainingException;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Pelatihan saya — layanan mandiri pegawai (PRD-001 §19.1, HR-8a; OD-HR-16). Tanpa permission: akun tertaut ke data
 * pegawai di yayasan aktif. Pegawai melihat semua riwayatnya, mengajukan pelatihan baru (menunggu verifikasi Admin
 * SDM), menarik pengajuan yang masih menunggu, dan mengunduh lampirannya. Catatan pegawai lain → 404.
 */
final class MyTrainingController
{
    public function __construct(
        private readonly TenantContext $tenantContext,
        private readonly EmployeeLookup $lookup,
    ) {}

    public function index(TrainingRecords $records): Response
    {
        $employeeId = $this->employeeId();

        return Inertia::render('hr/my-trainings', [
            'linked' => $employeeId !== null,
            'trainings' => $employeeId === null ? [] : $records->forEmployee($this->tenantContext->require()->tenantId, $employeeId),
            'form' => TrainingController::formOptions(),
        ]);
    }

    public function store(Request $request, ManageTrainings $trainings): RedirectResponse
    {
        $employeeId = $this->employeeId() ?? abort(404);
        TrainingController::validateFile($request);
        $context = $this->tenantContext->require();
        $file = $request->file('file');

        try {
            $trainings->record($context->tenantId, $employeeId, TrainingController::input($request), $file instanceof UploadedFile ? $file : null, $context->membershipId, bySelf: true);
        } catch (TrainingException $exception) {
            throw ValidationException::withMessages([$exception->field => $exception->getMessage()]);
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Pengajuan pelatihan terkirim. Menunggu verifikasi Admin SDM.']);

        return back();
    }

    public function withdraw(string $training, ManageTrainings $trainings): RedirectResponse
    {
        $employeeId = $this->employeeId() ?? abort(404);

        try {
            $trainings->withdraw($this->tenantContext->require()->tenantId, $employeeId, $training);
        } catch (TrainingException $exception) {
            throw ValidationException::withMessages([$exception->field => $exception->getMessage()]);
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Pengajuan pelatihan ditarik.']);

        return back();
    }

    public function download(string $training, ManageTrainings $trainings): BinaryFileResponse
    {
        $employeeId = $this->employeeId() ?? abort(404);

        try {
            $file = $trainings->attachment($this->tenantContext->require()->tenantId, $employeeId, $training);
        } catch (TrainingException) {
            abort(404);
        }

        return DisciplineController::send($file);
    }

    private function employeeId(): ?string
    {
        $context = $this->tenantContext->require();

        return $this->lookup->employeeIdForMembership($context->tenantId, $context->membershipId);
    }
}
