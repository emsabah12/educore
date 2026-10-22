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
use Modules\Core\Domain\Tenancy\TenantContext;
use Modules\HR\Application\Authorization\HrAccess;
use Modules\HR\Application\Leave\LeaveApprovals;
use Modules\HR\Domain\Leave\Exceptions\LeaveException;
use Modules\HR\Domain\Leave\LeaveRequest;
use Modules\HR\Domain\Leave\LeaveStatus;

/**
 * Kotak masuk Persetujuan cuti (PRD-001 §13.4, HR-3c).
 *
 * Halaman: pemilik `hr.leave.approve` (tahap lembaga) atau `hr.leave.manage` (tahap SDM); selain itu 403.
 * Memutuskan: pengajuan yang tahapnya tidak boleh ia putuskan → 404.
 */
final class LeaveApprovalController
{
    public function __construct(
        private readonly AuthorizationService $authorization,
        private readonly TenantContext $tenantContext,
    ) {}

    public function index(LeaveApprovals $approvals): Response
    {
        $this->authorizePage();
        $context = $this->tenantContext->require();

        return Inertia::render('hr/leave-approvals', [
            'requests' => $approvals->inbox($context->tenantId, $context->membershipId),
            'noteMax' => LeaveApprovals::NOTE_MAX_LENGTH,
        ]);
    }

    public function decide(Request $request, string $leave, LeaveApprovals $approvals): RedirectResponse
    {
        $this->authorizePage();
        $target = Str::isUuid($leave) ? LeaveRequest::query()->find($leave) : null;

        // Hanya yang boleh memutuskan tahap berjalan; pengajuan yang sudah diputuskan diberi pesan di service.
        if (! $target instanceof LeaveRequest || ($target->status === LeaveStatus::Pending && ! $approvals->canDecide($target))) {
            abort(404);
        }

        $request->validate([
            'decision' => ['required', Rule::in(['setuju', 'tolak'])],
            'note' => ['nullable', 'string', 'max:'.LeaveApprovals::NOTE_MAX_LENGTH],
        ], ['decision.required' => 'Pilih setujui atau tolak.'], ['note' => 'catatan']);

        $approve = $request->string('decision')->value() === 'setuju';
        $context = $this->tenantContext->require();

        try {
            $status = $approvals->decide($context->tenantId, $target->id, $context->membershipId, $approve, $request->input('note') === null ? null : $request->string('note')->value());
        } catch (LeaveException $exception) {
            throw ValidationException::withMessages([$exception->field => $exception->getMessage()]);
        }

        $message = match ($status) {
            LeaveStatus::Approved => 'Cuti disetujui.',
            LeaveStatus::Rejected => 'Cuti ditolak.',
            default => 'Disetujui di tahap lembaga; menunggu persetujuan Admin SDM.',
        };

        Inertia::flash('toast', ['type' => 'success', 'message' => $message]);

        return redirect()->route('hr.leave-approvals.index');
    }

    private function authorizePage(): void
    {
        $allowed = $this->authorization->allows(HrAccess::LEAVE_APPROVE)
            || $this->authorization->inspectTarget(HrAccess::LEAVE_MANAGE, null, null)->allowed();

        if (! $allowed) {
            abort(403);
        }
    }
}
