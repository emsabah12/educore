<?php

declare(strict_types=1);

namespace Modules\Core\Platform\Http\Controllers\Api\v1;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Str;
use Modules\Core\Governance\Audit\Contracts\AuditTrailServiceInterface;
use Modules\Core\Http\Responses\ApiErrorResponse;
use Modules\Core\Jobs\SendAsynchronousNotificationJob;
use Modules\Core\Platform\Http\Requests\SendNotificationRequest;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

final class NotificationController extends Controller
{
    public function __construct(
        private readonly AuditTrailServiceInterface $auditTrail,
    ) {}

    /**
     * Memicu pengiriman notifikasi secara asynchronous.
     *
     * Canonical authentication context harus sudah disediakan oleh
     * InjectTenantContext:
     *
     * - authenticated_tenant_id
     * - authenticated_user_id
     */
    public function send(
        SendNotificationRequest $request,
    ): JsonResponse {
        $tenantId = $request->attributes->get(
            'authenticated_tenant_id',
        );

        $operatorId = $request->attributes->get(
            'authenticated_user_id',
        );

        /*
         * Defense-in-depth.
         *
         * Normal route composition sudah memakai InjectTenantContext.
         * Jika controller tetap menerima request tanpa canonical
         * Tenant context, request harus fail closed.
         */
        if (
            ! is_string($tenantId)
            || ! Str::isUuid(
                trim($tenantId),
            )
        ) {
            return $this
                ->authenticationContextDeniedResponse();
        }

        /*
         * Canonical authenticated user juga wajib tersedia.
         */
        if (
            ! is_string($operatorId)
            || ! Str::isUuid(
                trim($operatorId),
            )
        ) {
            return $this
                ->authenticationContextDeniedResponse();
        }

        $tenantId = trim(
            $tenantId,
        );

        $operatorId = trim(
            $operatorId,
        );

        /**
         * @var array{
         *     recipient: string,
         *     body: string,
         *     options?: array{
         *         title?: string|null
         *     }|null
         * } $payload
         */
        $payload = $request->validated();

        $options = $payload['options'] ?? [];

        if (! is_array($options)) {
            $options = [];
        }

        /*
         * ------------------------------------------------------------------
         * Dispatch Error Boundary
         * ------------------------------------------------------------------
         *
         * Hanya kegagalan dispatch yang boleh membuat endpoint
         * mengembalikan HTTP 500.
         *
         * Jika bagian ini berhasil, notification sudah diterima oleh
         * queue dan response akhir harus tetap 202.
         */
        try {
            SendAsynchronousNotificationJob::dispatch(
                $tenantId,
                $operatorId,
                [
                    'recipient' =>
                    $payload['recipient'],
                    'body' =>
                    $payload['body'],
                    'options' =>
                    $options,
                ],
            )->onConnection(
                'database',
            );
        } catch (Throwable $exception) {
            /*
             * report() menjaga diagnostic detail di server-side
             * observability tanpa mengekspos exception ke client.
             */
            report(
                $exception,
            );

            return ApiErrorResponse::make(
                code: 'NOTIFICATION_DISPATCH_FAILED',
                message: 'Failed to queue notification.',
                status: Response::HTTP_INTERNAL_SERVER_ERROR,
            );
        }

        /*
         * ------------------------------------------------------------------
         * Best-Effort Audit Boundary
         * ------------------------------------------------------------------
         *
         * Audit failure tetap dilaporkan untuk observability, tetapi
         * tidak boleh mengubah queue dispatch yang sudah berhasil
         * menjadi gagal.
         */
        try {
            $this->auditTrail->log(
                'notification.dispatched',
                sprintf(
                    'Notifikasi berhasil dijadwalkan ke penerima: %s',
                    $payload['recipient'],
                ),
                $tenantId,
                $operatorId,
                [
                    'recipient' =>
                    $payload['recipient'],
                    'channel' =>
                    'WHATSAPP',
                ],
            );
        } catch (Throwable $auditException) {
            report(
                $auditException,
            );
        }

        return response()->json(
            [
                'status' => 'success',
                'message' =>
                'Notification dispatch has been accepted and queued for transmission.',
            ],
            Response::HTTP_ACCEPTED,
        );
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
