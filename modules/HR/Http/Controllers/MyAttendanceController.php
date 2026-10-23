<?php

namespace Modules\HR\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Modules\Core\Domain\Tenancy\TenantContext;
use Modules\HR\Application\Attendance\ManageAttendance;
use Modules\HR\Application\Attendance\MyAttendance;
use Modules\HR\Contracts\EmployeeLookup;
use Modules\HR\Domain\Attendance\Exceptions\AttendanceException;

/**
 * Presensi saya — absen mandiri pegawai (PRD-001 §14.2, HR-4a).
 *
 * Tanpa permission: dasarnya akun yang login tertaut ke data pegawai di yayasan aktif. Akun tanpa
 * data pegawai melihat keterangan; aksi → 404.
 */
final class MyAttendanceController
{
    public function __construct(
        private readonly TenantContext $tenantContext,
        private readonly EmployeeLookup $lookup,
    ) {}

    public function index(MyAttendance $attendance): Response
    {
        $context = $this->tenantContext->require();
        $employeeId = $this->lookup->employeeIdForMembership($context->tenantId, $context->membershipId);

        if ($employeeId === null) {
            return Inertia::render('hr/my-attendance', ['linked' => false]);
        }

        return Inertia::render('hr/my-attendance', ['linked' => true] + $attendance->build($context->tenantId, $employeeId));
    }

    public function checkIn(ManageAttendance $attendance): RedirectResponse
    {
        [$tenantId, $employeeId] = $this->employee();

        try {
            $result = $attendance->checkIn($tenantId, $employeeId);
        } catch (AttendanceException $exception) {
            throw ValidationException::withMessages([$exception->field => $exception->getMessage()]);
        }

        $time = str_replace(':', '.', $result['time']);
        $message = $result['late'] > 0
            ? "Absen masuk tercatat pukul {$time} (terlambat {$result['late']} menit)."
            : "Absen masuk tercatat pukul {$time}.";

        Inertia::flash('toast', ['type' => 'success', 'message' => $message]);

        return redirect()->route('hr.my-attendance.index');
    }

    public function checkOut(ManageAttendance $attendance): RedirectResponse
    {
        [$tenantId, $employeeId] = $this->employee();

        try {
            $result = $attendance->checkOut($tenantId, $employeeId);
        } catch (AttendanceException $exception) {
            throw ValidationException::withMessages([$exception->field => $exception->getMessage()]);
        }

        $time = str_replace(':', '.', $result['time']);
        $message = $result['early'] > 0
            ? "Absen pulang tercatat pukul {$time} ({$result['early']} menit lebih awal dari jam pulang)."
            : "Absen pulang tercatat pukul {$time}.";

        Inertia::flash('toast', ['type' => 'success', 'message' => $message]);

        return redirect()->route('hr.my-attendance.index');
    }

    /**
     * @return array{0: string, 1: string} id yayasan, id pegawai; akun tanpa data pegawai → 404
     */
    private function employee(): array
    {
        $context = $this->tenantContext->require();
        $employeeId = $this->lookup->employeeIdForMembership($context->tenantId, $context->membershipId);

        if ($employeeId === null) {
            abort(404);
        }

        return [$context->tenantId, $employeeId];
    }
}
