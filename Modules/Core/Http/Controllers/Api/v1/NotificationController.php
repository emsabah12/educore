<?php

declare(strict_types=1);

namespace Modules\Core\Http\Controllers\Api\v1;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Modules\Core\Jobs\SendAsynchronousNotificationJob;
use Modules\Core\Contracts\Auth\AuditTrailServiceInterface;
use Throwable;

final class NotificationController extends Controller
{
    private AuditTrailServiceInterface $auditTrail;

    public function __construct(AuditTrailServiceInterface $auditTrail)
    {
        $this->auditTrail = $auditTrail;
    }

    /**
     * Memicu pengiriman notifikasi massal/tunggal secara asynchronous.
     */
    public function send(Request $request): JsonResponse
    {
        $tenantId = $request->attributes->get('authenticated_tenant_id');
        $operatorId = $request->attributes->get('authenticated_user_id');

        if (! $tenantId) {
            return response()->json(['status' => 'error', 'message' => 'Unauthorized. Tenant context missing.'], 401);
        }

        $payload = $request->validate([
            'recipient' => ['required', 'string', 'max:150'],
            'body'      => ['required', 'string'],
            'options'   => ['nullable', 'array'],
            'options.title' => ['nullable', 'string', 'max:200'],
            'options.user_id' => ['nullable', 'string', 'uuid'],
        ]);

        try {
            // Dorong masuk ke dalam antrean database (Non-blocking execution)
            SendAsynchronousNotificationJob::dispatch(
                $tenantId,
                $operatorId,
                [
                    'recipient' => $payload['recipient'],
                    'body'      => $payload['body'],
                    'options'   => $payload['options'] ?? []
                ]
            )->onConnection('database');

            // Catat ke log audit trail internal platform
            $this->auditTrail->log(
                'notification.dispatched',
                sprintf('Notifikasi berhasil dijadwalkan ke penerima: %s', $payload['recipient']),
                $tenantId,
                $operatorId,
                [
                    'recipient' => $payload['recipient'],
                    'channel' => 'WHATSAPP'
                ]
            );

            return response()->json([
                'status'  => 'success',
                'message' => 'Notification dispatch has been accepted and queued for transmission.'
            ], 202);
        } catch (Throwable $e) {
            return response()->json(['status' => 'error', 'message' => 'Failed to queue notification.'], 500);
        }
    }
}
