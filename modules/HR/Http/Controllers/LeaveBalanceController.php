<?php

namespace Modules\HR\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Modules\Core\Application\Authorization\AuthorizationService;
use Modules\Core\Domain\Organization\Organization;
use Modules\Core\Domain\Organization\OrganizationStatus;
use Modules\Core\Domain\Organization\OrganizationType;
use Modules\Core\Domain\Tenancy\TenantContext;
use Modules\Core\Support\BusinessClock;
use Modules\HR\Application\Authorization\HrAccess;
use Modules\HR\Application\Leave\LeaveBalanceSheet;
use Modules\HR\Application\Leave\LeaveRecapExport;
use Modules\HR\Application\Leave\ManageLeaveAdjustments;
use Modules\HR\Domain\Leave\Exceptions\LeaveException;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Saldo cuti, penyesuaian jatah, dan rekap .xlsx (PRD-001 §13.5, HR-3d-b).
 * Butuh `hr.leave.manage` di tingkat Yayasan (Admin SDM, Admin Yayasan); tanpa izin 403.
 */
final class LeaveBalanceController
{
    public function __construct(
        private readonly AuthorizationService $authorization,
        private readonly TenantContext $tenantContext,
    ) {}

    public function index(Request $request, LeaveBalanceSheet $sheet): Response
    {
        $this->authorize();
        $tenantId = $this->tenantId();
        $thisYear = (int) BusinessClock::today()->format('Y');

        $filters = LeaveBalanceSheet::filters($request->query->all(), $thisYear);
        ['rows' => $rows, 'page' => $page] = $sheet->page($tenantId, $filters);

        $types = [];

        foreach ($sheet->types($tenantId) as $type) {
            $types[] = ['value' => $type->id, 'label' => $type->name];
        }

        $years = [];

        for ($year = $thisYear + 1; $year >= $thisYear - 5; $year--) {
            $years[] = ['value' => (string) $year, 'label' => (string) $year];
        }

        return Inertia::render('hr/leaves/balances', [
            'employees' => [
                'data' => $rows,
                'current_page' => $page->currentPage(),
                'last_page' => $page->lastPage(),
                'total' => $page->total(),
                'from' => $page->firstItem(),
                'to' => $page->lastItem(),
                'prev_page_url' => $page->previousPageUrl(),
                'next_page_url' => $page->nextPageUrl(),
            ],
            'filters' => $filters,
            'options' => [
                'organizations' => $this->organizationOptions($tenantId),
                'types' => $types,
                'years' => $years,
            ],
            // Penyesuaian hanya untuk tahun berjalan & tahun sebelumnya.
            'canAdjust' => $filters['tahun'] === $thisYear || $filters['tahun'] === $thisYear - 1,
            'limits' => ['reason' => ManageLeaveAdjustments::REASON_MAX_LENGTH, 'days' => ManageLeaveAdjustments::MAX_DAYS],
        ]);
    }

    public function adjust(Request $request, string $employee, ManageLeaveAdjustments $adjustments): RedirectResponse
    {
        $this->authorize();
        $context = $this->tenantContext->require();

        $request->validate([
            'leave_type_id' => ['required', 'uuid'],
            'year' => ['required', 'integer', 'between:2000,2100'],
            'days' => ['required', 'integer', 'between:-'.ManageLeaveAdjustments::MAX_DAYS.','.ManageLeaveAdjustments::MAX_DAYS],
            'reason' => ['required', 'string', 'max:'.ManageLeaveAdjustments::REASON_MAX_LENGTH],
        ], [
            'leave_type_id.required' => 'Pilih jenis cuti.',
            'days.required' => 'Isi jumlah hari.',
            'reason.required' => 'Tulis alasan penyesuaian.',
        ], ['year' => 'tahun', 'days' => 'jumlah hari', 'reason' => 'alasan']);

        try {
            $result = $adjustments->adjust(
                $context->tenantId,
                $employee,
                $request->string('leave_type_id')->value(),
                $request->integer('year'),
                $request->integer('days'),
                $request->string('reason')->value(),
                $context->membershipId,
            );
        } catch (LeaveException $exception) {
            throw ValidationException::withMessages([$exception->field => $exception->getMessage()]);
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => "Jatah {$result['type']} disesuaikan; sisa sekarang {$result['remaining']} hari."]);

        return back();
    }

    public function export(Request $request, LeaveRecapExport $export): BinaryFileResponse
    {
        $this->authorize();
        $thisYear = (int) BusinessClock::today()->format('Y');

        $filters = LeaveBalanceSheet::filters($request->query->all(), $thisYear);
        // Rekap tidak dipersempit ke satu pegawai/pencarian: saringan lembaga & jenis saja.
        $filters['pegawai'] = '';
        $filters['cari'] = '';

        $requested = $request->integer('bulan');
        $month = $requested >= 1 && $requested <= 12 ? $requested : null;

        $path = tempnam(sys_get_temp_dir(), 'educore-rekap-cuti-');

        if ($path === false) {
            abort(500, 'Rekap tidak bisa dibuat.');
        }

        $export->write($this->tenantId(), $filters, $month, $path);

        return response()
            ->download($path, LeaveRecapExport::fileName($filters['tahun'], $month), [
                'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            ])
            ->deleteFileAfterSend();
    }

    // ── Bantuan ───────────────────────────────────────────────────────────────

    private function authorize(): void
    {
        $this->authorization->inspectTarget(HrAccess::LEAVE_MANAGE, null, null)->authorize();
    }

    private function tenantId(): string
    {
        return $this->tenantContext->require()->tenantId;
    }

    /**
     * @return list<array{value: string, label: string}>
     */
    private function organizationOptions(string $tenantId): array
    {
        $options = [];

        $organizations = Organization::query()
            ->withoutGlobalScope('tenant')
            ->where('tenant_id', $tenantId)
            ->whereIn('type', [OrganizationType::Unit->value, OrganizationType::Lembaga->value])
            ->where('status', OrganizationStatus::Active->value)
            ->orderBy('name')
            ->get();

        foreach ($organizations as $organization) {
            $options[] = ['value' => $organization->id, 'label' => $organization->name];
        }

        return $options;
    }
}
