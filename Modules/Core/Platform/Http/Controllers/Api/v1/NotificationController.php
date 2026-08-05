<?php

declare(strict_types=1);

namespace Modules\Core\Platform\Http\Controllers\Api\v1;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Str;
use Modules\Core\Governance\Audit\Contracts\AuditTrailServiceInterface;
use Modules\Core\Jobs\SendAsynchronousNotificationJob;
use Modules\Core\Platform\Http\Requests\SendNotificationRequest;
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
         * Defense-in-depth bila controller dipanggil tanpa middleware
         * atau canonical tenant context tidak valid.
         */
        if (
            ! is_string($tenantId)
            || ! Str::isUuid(trim($tenantId))
        ) {
            return response()->json([
                'status' => 'error',
                'message' => 'Unauthorized. Tenant context missing.',
            ], 401);
        }

        /*
         * Route ini selalu memakai InjectTenantContext, sehingga
         * authenticated user wajib tersedia dan berupa UUID valid.
         */
        if (
            ! is_string($operatorId)
            || ! Str::isUuid(trim($operatorId))
        ) {
            return response()->json([
                'status' => 'error',
                'message' => 'Unauthorized. User context missing.',
            ], 401);
        }

        $tenantId = trim($tenantId);
        $operatorId = trim($operatorId);

        /** @var array{
         *     recipient: string,
         *     body: string,
         *     options?: array{title?: string|null}|null
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
         * Hanya kegagalan dispatch yang boleh membuat endpoint mengembalikan
         * HTTP 500. Jika bagian ini berhasil, notification sudah diterima
         * oleh queue dan response akhir harus tetap 202.
         */
        try {
            SendAsynchronousNotificationJob::dispatch(
                $tenantId,
                $operatorId,
                [
                    'recipient' => $payload['recipient'],
                    'body' => $payload['body'],
                    'options' => $options,
                ],
            )->onConnection('database');
        } catch (Throwable $exception) {
            report($exception);

            return response()->json([
                'status' => 'error',
                'message' => 'Failed to queue notification.',
            ], 500);
        }

        /*
         * ------------------------------------------------------------------
         * Best-Effort Audit Boundary
         * ------------------------------------------------------------------
         *
         * Audit failure tetap dilaporkan untuk observability, tetapi tidak
         * boleh mengubah queue dispatch yang sudah berhasil menjadi gagal.
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
                    'recipient' => $payload['recipient'],
                    'channel' => 'WHATSAPP',
                ],
            );
        } catch (Throwable $auditException) {
            report($auditException);
        }

        return response()->json([
            'status' => 'success',
            'message' => 'Notification dispatch has been accepted and queued for transmission.',
        ], 202);
    }
}
