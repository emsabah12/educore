<?php

namespace Modules\HR\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Modules\Core\Domain\Tenancy\TenantContext;
use Modules\Core\Support\BusinessClock;
use Modules\HR\Application\Leave\LeaveApprovalSetting;
use Modules\HR\Application\Leave\LeaveBalances;
use Modules\HR\Application\Leave\ManageLeaveRequests;
use Modules\HR\Contracts\EmployeeLookup;
use Modules\HR\Domain\Leave\Exceptions\LeaveException;
use Modules\HR\Domain\Leave\LeaveRequest;
use Modules\HR\Domain\Leave\LeaveStatus;
use Modules\HR\Domain\Leave\LeaveType;

/**
 * Cuti saya — layanan mandiri pegawai (PRD-001 §13.3, HR-3b): saldo, ajukan, riwayat, tarik.
 *
 * Tanpa permission: dasarnya akun yang sedang login tertaut ke data pegawai di yayasan aktif.
 * Akun tanpa data pegawai melihat keterangan; aksi → 404. Pengajuan orang lain → 404.
 */
final class MyLeaveController
{
    public function __construct(
        private readonly TenantContext $tenantContext,
        private readonly EmployeeLookup $lookup,
    ) {}

    public function index(LeaveBalances $balances): Response
    {
        $context = $this->tenantContext->require();
        $employeeId = $this->lookup->employeeIdForMembership($context->tenantId, $context->membershipId);
        $year = (int) BusinessClock::today()->format('Y');

        if ($employeeId === null) {
            return Inertia::render('hr/my-leave', ['linked' => false]);
        }

        $types = [];

        foreach (LeaveType::query()->withoutGlobalScope('tenant')->where('tenant_id', $context->tenantId)->where('is_active', true)->orderBy('name')->get() as $type) {
            $types[] = ['id' => $type->id, 'name' => $type->name, 'has_quota' => $type->has_quota, 'max_days' => $type->max_days];
        }

        return Inertia::render('hr/my-leave', [
            'linked' => true,
            'year' => $year,
            'balances' => $balances->forEmployee($context->tenantId, $employeeId, $year),
            'types' => $types,
            'requests' => $this->history($context->tenantId, $employeeId),
            'today' => BusinessClock::todayString(),
            'limits' => [
                'reason' => ManageLeaveRequests::REASON_MAX_LENGTH,
                'backdate' => ManageLeaveRequests::BACKDATE_DAYS,
            ],
        ]);
    }

    /**
     * Pratinjau jumlah hari kerja sebelum mengirim pengajuan.
     */
    public function preview(Request $request, ManageLeaveRequests $requests): JsonResponse
    {
        [$tenantId, $employeeId] = $this->employee();

        try {
            $plan = $requests->plan($tenantId, $employeeId, $request->string('mulai')->value(), $request->string('selesai')->value());
        } catch (LeaveException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        }

        return response()->json(['days' => $plan['days'], 'days_by_year' => $plan['days_by_year'], 'organization' => $plan['organization']]);
    }

    public function store(Request $request, ManageLeaveRequests $requests): RedirectResponse
    {
        [$tenantId, $employeeId] = $this->employee();

        $request->validate([
            'leave_type_id' => ['required', 'uuid'],
            'start_date' => ['required', 'date_format:Y-m-d'],
            'end_date' => ['required', 'date_format:Y-m-d'],
            'reason' => ['required', 'string', 'max:'.ManageLeaveRequests::REASON_MAX_LENGTH],
        ], [
            'leave_type_id.required' => 'Pilih jenis cuti.',
            'reason.required' => 'Tulis alasan cuti.',
        ], ['start_date' => 'tanggal mulai', 'end_date' => 'tanggal selesai', 'reason' => 'alasan']);

        try {
            $leave = $requests->submit(
                $tenantId,
                $employeeId,
                $request->string('leave_type_id')->value(),
                $request->string('start_date')->value(),
                $request->string('end_date')->value(),
                $request->string('reason')->value(),
            );
        } catch (LeaveException $exception) {
            throw ValidationException::withMessages([$exception->field => $exception->getMessage()]);
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => "Pengajuan cuti {$leave->days} hari terkirim dan menunggu persetujuan."]);

        return redirect()->route('hr.my-leave.index');
    }

    public function withdraw(string $leave, ManageLeaveRequests $requests): RedirectResponse
    {
        [$tenantId, $employeeId] = $this->employee();

        try {
            $requests->withdraw($tenantId, $employeeId, $leave);
        } catch (LeaveException $exception) {
            throw ValidationException::withMessages([$exception->field => $exception->getMessage()]);
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Pengajuan cuti ditarik.']);

        return redirect()->route('hr.my-leave.index');
    }

    // ── Bantuan ───────────────────────────────────────────────────────────────

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

    /**
     * Riwayat pengajuan pegawai ini, terbaru dulu (maks. 100).
     *
     * @return list<array{id: string, type: string, start_date: string, end_date: string, days: int, status: string, status_label: string, step: int|null, steps: int, steps_label: string, reason: string, created_at: string|null}>
     */
    private function history(string $tenantId, string $employeeId): array
    {
        $names = DB::table('hr_leave_types')->where('tenant_id', $tenantId)->pluck('name', 'id')->all();
        $rows = [];

        $requests = LeaveRequest::query()->withoutGlobalScope('tenant')->where('employee_id', $employeeId)->orderByDesc('start_date')->orderByDesc('created_at')->limit(100)->get();

        foreach ($requests as $request) {
            $rows[] = [
                'id' => $request->id,
                'type' => (string) ($names[$request->leave_type_id] ?? '—'),
                'start_date' => $request->start_date->toDateString(),
                'end_date' => $request->end_date->toDateString(),
                'days' => $request->days,
                'status' => $request->status->value,
                'status_label' => $request->status === LeaveStatus::Pending && $request->approval_steps === 2
                    ? 'Menunggu tahap '.$request->current_step.' dari 2'
                    : $request->status->label(),
                'step' => $request->current_step,
                'steps' => $request->approval_steps,
                'steps_label' => LeaveApprovalSetting::label($request->approval_steps),
                'reason' => $request->reason,
                'created_at' => $request->created_at?->toIso8601String(),
            ];
        }

        return $rows;
    }
}
