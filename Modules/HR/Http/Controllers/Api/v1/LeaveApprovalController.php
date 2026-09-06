<?php

declare(strict_types=1);

namespace Modules\HR\Http\Controllers\Api\v1;

use App\Http\Controllers\Controller;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Modules\Core\Http\Responses\ApiErrorResponse;
use Modules\HR\Exceptions\LeaveLifecycleException;
use Modules\HR\Http\Requests\DecideLeaveRequestRequest;
use Modules\HR\Models\LeaveRequestApprovalStep;
use Modules\HR\Services\LeaveApprovalService;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

final class LeaveApprovalController extends Controller
{
    public function __construct(
        private readonly LeaveApprovalService $approvalService,
    ) {}

    /**
     * §15.6 — GET /leave-approvals/pending.
     *
     * [KETERBATASAN LINGKUP JUJUR]: endpoint ini me-list SEMUA step yang
     * SAAT INI actionable (step_order PENDING terkecil per Leave
     * Request) tenant-wide — akses ke endpoint sudah digerbang izin
     * `hr.leave.approve`, tapi listing di sini TIDAK memfilter ulang
     * per-baris apakah actor punya scope persis untuk step tertentu
     * (REQUEST_PLACEMENT/ORGANIZATION vs ambient context aktor).
     * Pemeriksaan otorisasi PENUH & ketat tetap terjadi di
     * `approveCurrentStep()`/`rejectCurrentStep()` saat keputusan
     * benar-benar diambil — dokumen Phase 2C tidak mendefinisikan
     * algoritma query untuk pre-filtering listing per-scope, jadi saya
     * tidak mengarang satu yang tidak berdasar.
     */
    public function pending(Request $request): JsonResponse
    {
        $tenantId = $request->attributes->get('authenticated_tenant_id');

        if (! $this->isCanonicalUuid($tenantId)) {
            return $this->authenticationContextDeniedResponse();
        }

        $perPage = max(1, min((int) $request->query('per_page', '15'), 100));

        $steps = LeaveRequestApprovalStep::query()
            ->where('status', LeaveRequestApprovalStep::STATUS_PENDING)
            ->whereIn(
                'step_order',
                function (QueryBuilder $query): void {
                    $query->selectRaw('MIN(inner_steps.step_order)')
                        ->from('leave_request_approval_steps as inner_steps')
                        ->whereColumn('inner_steps.leave_request_id', 'leave_request_approval_steps.leave_request_id')
                        ->where('inner_steps.status', LeaveRequestApprovalStep::STATUS_PENDING);
                },
            )
            ->with('leaveRequest')
            ->orderBy('created_at')
            ->paginate($perPage);

        return response()->json([
            'status' => 'success',
            'data' => $steps->items(),
            'meta' => [
                'current_page' => $steps->currentPage(),
                'last_page' => $steps->lastPage(),
                'per_page' => $steps->perPage(),
                'total' => $steps->total(),
            ],
        ]);
    }

    public function approve(DecideLeaveRequestRequest $request, string $leaveRequestId): JsonResponse
    {
        return $this->decide($request, $leaveRequestId, approve: true);
    }

    public function reject(DecideLeaveRequestRequest $request, string $leaveRequestId): JsonResponse
    {
        return $this->decide($request, $leaveRequestId, approve: false);
    }

    private function decide(DecideLeaveRequestRequest $request, string $leaveRequestId, bool $approve): JsonResponse
    {
        $tenantId = $request->attributes->get('authenticated_tenant_id');
        $membershipId = $request->attributes->get('authenticated_membership_id');

        if (! $this->isCanonicalUuid($tenantId) || ! $this->isCanonicalUuid($membershipId)) {
            return $this->authenticationContextDeniedResponse();
        }

        /** @var array{note: string|null} $payload */
        $payload = $request->validated();

        try {
            $leaveRequest = $approve
                ? $this->approvalService->approveCurrentStep($tenantId, $leaveRequestId, $membershipId)
                : $this->approvalService->rejectCurrentStep($tenantId, $leaveRequestId, $membershipId, $payload['note'] ?? '');
        } catch (ModelNotFoundException) {
            return ApiErrorResponse::make(
                code: 'LEAVE_REQUEST_NOT_FOUND',
                message: sprintf('Leave Request [%s] was not found in the current tenant.', $leaveRequestId),
                status: Response::HTTP_NOT_FOUND,
            );
        } catch (LeaveLifecycleException $exception) {
            return $this->decisionConflictResponse($exception);
        } catch (Throwable $exception) {
            Log::error(
                'Leave Request decision failed.',
                [
                    'tenant_id' => $tenantId,
                    'leave_request_id' => $leaveRequestId,
                    'decision' => $approve ? 'approve' : 'reject',
                    'exception_class' => $exception::class,
                ],
            );

            return ApiErrorResponse::make(
                code: 'LEAVE_REQUEST_DECISION_FAILED',
                message: 'Failed to record Leave Request decision.',
                status: Response::HTTP_INTERNAL_SERVER_ERROR,
            );
        }

        return response()->json([
            'status' => 'success',
            'data' => $leaveRequest,
        ]);
    }

    /**
     * `LEAVE_SELF_APPROVAL_FORBIDDEN` dan `LEAVE_SCOPE_MISMATCH`
     * (otorisasi) dilaporkan 403; sisanya (urutan step, saldo tidak
     * cukup, overlap) 409 — sesuai kelas kegagalan masing-masing.
     */
    private function decisionConflictResponse(LeaveLifecycleException $exception): JsonResponse
    {
        $message = $exception->getMessage();

        $authorizationCodes = [
            'LEAVE_SELF_APPROVAL_FORBIDDEN',
            'LEAVE_SCOPE_MISMATCH',
            'LEAVE_APPROVER_NOT_AUTHORIZED',
        ];

        foreach ($authorizationCodes as $code) {
            if (str_contains($message, $code)) {
                return ApiErrorResponse::make(
                    code: 'LEAVE_REQUEST_DECISION_FORBIDDEN',
                    message: $message,
                    status: Response::HTTP_FORBIDDEN,
                );
            }
        }

        return ApiErrorResponse::make(
            code: 'LEAVE_REQUEST_DECISION_CONFLICT',
            message: $message,
            status: Response::HTTP_CONFLICT,
        );
    }

    /**
     * @phpstan-assert-if-true string $value
     */
    private function isCanonicalUuid(mixed $value): bool
    {
        return is_string($value)
            && Str::isUuid(trim($value));
    }

    private function authenticationContextDeniedResponse(): JsonResponse
    {
        return ApiErrorResponse::make(
            code: 'AUTHENTICATION_CONTEXT_DENIED',
            message: 'Authentication context missing or invalid.',
            status: Response::HTTP_FORBIDDEN,
        );
    }
}
