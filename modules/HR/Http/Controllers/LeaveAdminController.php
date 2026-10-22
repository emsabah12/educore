<?php

namespace Modules\HR\Http\Controllers;

use Illuminate\Http\JsonResponse;
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
use Modules\HR\Application\Leave\LeaveBalances;
use Modules\HR\Application\Leave\LeaveEmployeePicker;
use Modules\HR\Application\Leave\LeaveRegister;
use Modules\HR\Application\Leave\ManageLeaveRequests;
use Modules\HR\Domain\Leave\Exceptions\LeaveException;
use Modules\HR\Domain\Leave\LeaveStatus;
use Modules\HR\Domain\Leave\LeaveType;

/**
 * Kelola cuti — Admin SDM (PRD-001 §13.5, HR-3d-a): daftar semua pengajuan + saringan, detail &
 * riwayat keputusan, catat atas nama pegawai (langsung disetujui), batalkan cuti yang disetujui.
 * Butuh `hr.leave.manage` di tingkat Yayasan; tanpa izin 403. Pengajuan yayasan lain → 404.
 */
final class LeaveAdminController
{
    public function __construct(
        private readonly AuthorizationService $authorization,
        private readonly TenantContext $tenantContext,
    ) {}

    public function index(Request $request, LeaveRegister $register): Response
    {
        $this->authorize();
        $tenantId = $this->tenantId();

        $filters = LeaveRegister::filters($request->query->all());
        ['rows' => $rows, 'page' => $page] = $register->search($tenantId, $filters);

        $statuses = [];

        foreach (LeaveStatus::cases() as $status) {
            $statuses[] = ['value' => $status->value, 'label' => $status->label()];
        }

        return Inertia::render('hr/leaves/index', [
            'leaves' => [
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
                'types' => $this->typeOptions($tenantId, includeInactive: true),
                'statuses' => $statuses,
            ],
        ]);
    }

    public function show(string $leave, LeaveRegister $register): Response
    {
        $this->authorize();
        $context = $this->tenantContext->require();

        $detail = $register->detail($context->tenantId, $leave);

        if ($detail === null) {
            abort(404);
        }

        $own = $detail['employee_membership_id'] === $context->membershipId;
        unset($detail['employee_membership_id']);

        return Inertia::render('hr/leaves/show', [
            'leave' => $detail,
            'canCancel' => $detail['status'] === LeaveStatus::Approved->value && ! $own,
            'own' => $own,
            'reasonMax' => ManageLeaveRequests::CANCEL_REASON_MAX_LENGTH,
        ]);
    }

    /**
     * Halaman catat cuti atas nama: ?cari= mencari pegawai, ?pegawai= memilih pegawai.
     */
    public function create(Request $request, LeaveEmployeePicker $picker, LeaveBalances $balances): Response
    {
        $this->authorize();
        $tenantId = $this->tenantId();

        $search = mb_substr(trim($request->string('cari')->value()), 0, 100);
        $employee = $picker->find($tenantId, $request->string('pegawai')->value());
        $year = (int) BusinessClock::today()->format('Y');

        return Inertia::render('hr/leaves/record', [
            'search' => $search,
            'candidates' => $employee === null ? $picker->search($tenantId, $search) : [],
            'employee' => $employee,
            'balances' => $employee === null ? [] : $balances->forEmployee($tenantId, $employee['id'], $year),
            'types' => $this->typeOptions($tenantId, includeInactive: false),
            'year' => $year,
            'today' => BusinessClock::todayString(),
            'limits' => [
                'reason' => ManageLeaveRequests::REASON_MAX_LENGTH,
                'backdate' => ManageLeaveRequests::RECORD_BACKDATE_DAYS,
                'minSearch' => LeaveEmployeePicker::MIN_SEARCH_LENGTH,
            ],
        ]);
    }

    /**
     * Pratinjau jumlah hari kerja untuk pegawai terpilih.
     */
    public function preview(Request $request, ManageLeaveRequests $requests, LeaveEmployeePicker $picker): JsonResponse
    {
        $this->authorize();
        $tenantId = $this->tenantId();
        $employee = $picker->find($tenantId, $request->string('pegawai')->value());

        if ($employee === null) {
            return response()->json(['message' => 'Pilih pegawai aktif yang punya penempatan utama.'], 422);
        }

        try {
            $plan = $requests->plan($tenantId, $employee['id'], $request->string('mulai')->value(), $request->string('selesai')->value(), ManageLeaveRequests::RECORD_BACKDATE_DAYS);
        } catch (LeaveException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        }

        return response()->json(['days' => $plan['days'], 'days_by_year' => $plan['days_by_year'], 'organization' => $plan['organization']]);
    }

    public function store(Request $request, ManageLeaveRequests $requests, LeaveEmployeePicker $picker): RedirectResponse
    {
        $this->authorize();
        $context = $this->tenantContext->require();

        $request->validate([
            'employee_id' => ['required', 'uuid'],
            'leave_type_id' => ['required', 'uuid'],
            'start_date' => ['required', 'date_format:Y-m-d'],
            'end_date' => ['required', 'date_format:Y-m-d'],
            'reason' => ['required', 'string', 'max:'.ManageLeaveRequests::REASON_MAX_LENGTH],
        ], [
            'employee_id.required' => 'Pilih pegawai.',
            'leave_type_id.required' => 'Pilih jenis cuti.',
            'reason.required' => 'Tulis alasan / keterangan cuti.',
        ], ['employee_id' => 'pegawai', 'start_date' => 'tanggal mulai', 'end_date' => 'tanggal selesai', 'reason' => 'alasan']);

        $employee = $picker->find($context->tenantId, $request->string('employee_id')->value());

        if ($employee === null) {
            throw ValidationException::withMessages(['form' => 'Pegawai ini tidak aktif atau belum punya penempatan utama.']);
        }

        try {
            $leave = $requests->record(
                $context->tenantId,
                $employee['id'],
                $request->string('leave_type_id')->value(),
                $request->string('start_date')->value(),
                $request->string('end_date')->value(),
                $request->string('reason')->value(),
                $context->membershipId,
            );
        } catch (LeaveException $exception) {
            throw ValidationException::withMessages([$exception->field => $exception->getMessage()]);
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => "Cuti {$employee['name']} ({$leave->days} hari) dicatat dan langsung disetujui."]);

        return redirect()->route('hr.leaves.show', ['leave' => $leave->id]);
    }

    public function cancel(Request $request, string $leave, ManageLeaveRequests $requests, LeaveRegister $register): RedirectResponse
    {
        $this->authorize();
        $context = $this->tenantContext->require();

        if ($register->detail($context->tenantId, $leave) === null) {
            abort(404);
        }

        $request->validate([
            'reason' => ['required', 'string', 'max:'.ManageLeaveRequests::CANCEL_REASON_MAX_LENGTH],
        ], ['reason.required' => 'Tulis alasan pembatalan.'], ['reason' => 'alasan']);

        try {
            $requests->cancel($context->tenantId, $leave, $context->membershipId, $request->string('reason')->value());
        } catch (LeaveException $exception) {
            throw ValidationException::withMessages([$exception->field => $exception->getMessage()]);
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Cuti dibatalkan; jatahnya kembali.']);

        return redirect()->route('hr.leaves.show', ['leave' => $leave]);
    }

    // ── Bantuan ───────────────────────────────────────────────────────────────

    /**
     * Kelola cuti berlaku untuk seluruh yayasan: 403 bila tidak punya izin di tingkat Yayasan.
     */
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

    /**
     * @return list<array{value: string, label: string, has_quota: bool, max_days: int|null}>
     */
    private function typeOptions(string $tenantId, bool $includeInactive): array
    {
        $options = [];

        $types = LeaveType::query()
            ->withoutGlobalScope('tenant')
            ->where('tenant_id', $tenantId)
            ->when(! $includeInactive, fn ($query) => $query->where('is_active', true))
            ->orderBy('name')
            ->get();

        foreach ($types as $type) {
            $options[] = [
                'value' => $type->id,
                'label' => $type->is_active ? $type->name : "{$type->name} (nonaktif)",
                'has_quota' => $type->has_quota,
                'max_days' => $type->max_days,
            ];
        }

        return $options;
    }
}
