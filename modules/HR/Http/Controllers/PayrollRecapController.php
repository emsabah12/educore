<?php

namespace Modules\HR\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Modules\Core\Application\Authorization\AuthorizationService;
use Modules\Core\Domain\Organization\Organization;
use Modules\Core\Domain\Organization\OrganizationStatus;
use Modules\Core\Domain\Tenancy\TenantContext;
use Modules\Core\Support\BusinessClock;
use Modules\HR\Application\Attendance\AttendanceRecap;
use Modules\HR\Application\Authorization\HrAccess;
use Modules\HR\Application\Payroll\ManagePayroll;
use Modules\HR\Application\Payroll\PayrollRecapExport;
use Modules\HR\Domain\Payroll\Exceptions\PayrollException;
use Modules\HR\Domain\Payroll\PayComponentKind;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Rekap gaji bulanan (PRD-001 §16.3, HR-5c-1): `/pegawai/gaji/rekap?bulan=&lembaga=`, penyesuaian manual,
 * kunci/buka kunci, unduh .xlsx. Hanya `hr.payroll.manage` tingkat Yayasan (Admin Yayasan, Admin SDM
 * — termasuk bagian keuangan, OWNER 2026-10-27); selain itu 403.
 */
final class PayrollRecapController
{
    public function __construct(
        private readonly AuthorizationService $authorization,
        private readonly TenantContext $tenantContext,
    ) {}

    public function index(Request $request, ManagePayroll $payroll): Response
    {
        $this->authorize();
        $tenantId = $this->tenantContext->require()->tenantId;
        $month = ManagePayroll::month($request->query('bulan'));
        $organization = $this->organization($request->query('lembaga'));
        [, $until] = AttendanceRecap::range($month);

        $rows = $payroll->rows($tenantId, $month, $organization?->id);
        $totals = ['earning' => 0, 'deduction' => 0, 'net' => 0];

        foreach ($rows as $row) {
            $totals['earning'] += $row['earning'];
            $totals['deduction'] += $row['deduction'];
            $totals['net'] += $row['net'];
        }

        return Inertia::render('hr/payroll/recap', [
            'month' => $month,
            'maxMonth' => BusinessClock::today()->format('Y-m'),
            'label' => AttendanceRecap::monthLabel($month),
            'until' => $until,
            'lock' => $payroll->lockInfo($tenantId, $month),
            'organization' => $organization?->id,
            'organizations' => Organization::query()->where('status', OrganizationStatus::Active->value)->orderBy('code')->get()
                ->map(fn (Organization $item): array => ['value' => $item->id, 'label' => "{$item->name} ({$item->code})"])->values()->all(),
            'rows' => $rows,
            'totals' => $totals,
        ]);
    }

    public function lock(string $month, ManagePayroll $payroll): RedirectResponse
    {
        $this->authorize();
        $context = $this->tenantContext->require();

        $count = $this->attempt(fn () => $payroll->lock($context->tenantId, $month, $context->membershipId));

        return $this->done(AttendanceRecap::monthLabel($month)." dikunci: {$count} pegawai. Angkanya tidak berubah lagi kecuali kunci dibuka.");
    }

    public function unlock(Request $request, string $month, ManagePayroll $payroll): RedirectResponse
    {
        $this->authorize();

        $request->validate(['reason' => ['required', 'string', 'max:'.ManagePayroll::REASON_MAX]], [], ['reason' => 'alasan']);

        $this->attempt(fn () => $payroll->unlock($this->tenantContext->require()->tenantId, $month, $request->string('reason')->value()));

        return $this->done('Kunci '.AttendanceRecap::monthLabel($month).' dibuka. Rekap dihitung ulang dari data terkini.');
    }

    public function storeAdjustment(Request $request, string $month, ManagePayroll $payroll): RedirectResponse
    {
        $this->authorize();

        $request->validate([
            'employee_id' => ['required', 'uuid'],
            'kind' => ['required', Rule::enum(PayComponentKind::class)],
            'amount' => ['required', 'string', 'max:30'],
            'note' => ['required', 'string', 'max:'.ManagePayroll::NOTE_MAX],
        ], [], ['kind' => 'jenis', 'amount' => 'nominal', 'note' => 'keterangan']);

        $context = $this->tenantContext->require();

        $this->attempt(fn () => $payroll->addAdjustment(
            $context->tenantId,
            $month,
            $request->string('employee_id')->value(),
            PayComponentKind::from($request->string('kind')->value()),
            $request->input('amount'),
            $request->string('note')->value(),
            $context->membershipId,
        ));

        return $this->done('Penyesuaian ditambahkan.');
    }

    public function destroyAdjustment(string $month, string $adjustment, ManagePayroll $payroll): RedirectResponse
    {
        $this->authorize();

        $this->attempt(fn () => $payroll->removeAdjustment($this->tenantContext->require()->tenantId, $month, $adjustment));

        return $this->done('Penyesuaian dihapus.');
    }

    public function export(Request $request, ManagePayroll $payroll, PayrollRecapExport $export): BinaryFileResponse
    {
        $this->authorize();
        $tenantId = $this->tenantContext->require()->tenantId;
        $month = ManagePayroll::month($request->query('bulan'));
        $organization = $this->organization($request->query('lembaga'));
        $lock = $payroll->lockInfo($tenantId, $month);
        [, $until] = AttendanceRecap::range($month);
        $path = tempnam(sys_get_temp_dir(), 'educore-rekap-gaji-');

        if ($path === false) {
            abort(500, 'Rekap tidak bisa dibuat.');
        }

        $title = 'Rekap gaji — '.AttendanceRecap::monthLabel($month)
            .($organization === null ? '' : " — {$organization->name}")
            .($lock === null ? " (BELUM DIKUNCI, s.d. {$until})" : " (dikunci {$lock['locked_at']})");

        $export->write($payroll->payslips($tenantId, $month, $organization?->id), $title, $path);

        return response()
            ->download($path, PayrollRecapExport::fileName($month, $organization?->code), [
                'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            ])
            ->deleteFileAfterSend();
    }

    // ── Bantuan ───────────────────────────────────────────────────────────────

    private function authorize(): void
    {
        $this->authorization->inspectTarget(HrAccess::PAYROLL_MANAGE, null, null)->authorize();
    }

    /**
     * Lembaga saringan (yayasan aktif), atau null = semua.
     */
    private function organization(mixed $requested): ?Organization
    {
        if (! is_string($requested) || ! Str::isUuid($requested)) {
            return null;
        }

        $organization = Organization::query()->find($requested);

        return $organization instanceof Organization ? $organization : null;
    }

    /**
     * @template T
     *
     * @param  callable(): T  $action
     * @return T
     */
    private function attempt(callable $action): mixed
    {
        try {
            return $action();
        } catch (PayrollException $exception) {
            throw ValidationException::withMessages([$exception->field => $exception->getMessage()]);
        }
    }

    private function done(string $message): RedirectResponse
    {
        Inertia::flash('toast', ['type' => 'success', 'message' => $message]);

        return back();
    }
}
