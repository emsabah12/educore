<?php

declare(strict_types=1);

namespace Modules\Core\Platform\Http\Controllers\Api\v1;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Core\Governance\Audit\Contracts\AuditTrailServiceInterface;
use Modules\Core\Jobs\SendAsynchronousNotificationJob;
use Throwable;

final class NotificationController extends Controller
{
    public function __construct(
        private readonly AuditTrailServiceInterface $auditTrail
    ) {}

    /**
     * Memicu pengiriman notifikasi secara asynchronous.
     *
     * Tenant context wajib sudah disediakan oleh InjectTenantContext
     * sebelum request mencapai controller ini.
     */
    public function send(Request $request): JsonResponse
    {
        /*
         * Tenant context menggunakan kontrak standar yang disediakan
         * oleh Modules\Auth\Http\Middleware\InjectTenantContext.
         */
        $tenantUuid = $request->attributes->get('tenant_uuid');
        $userUuid = $request->attributes->get('user_uuid');

        /*
         * Defensive check.
         *
         * Secara normal request tanpa tenant context sudah ditolak
         * oleh InjectTenantContext dengan HTTP 403.
         *
         * Check ini tetap dipertahankan sebagai defense-in-depth
         * apabila controller dipanggil melalui jalur lain.
         */
        if (! is_string($tenantUuid) || $tenantUuid === '') {
            return response()->json([
                'status' => 'error',
                'message' => 'Unauthorized. Tenant context missing.',
            ], 401);
        }

        /*
         * User UUID bersifat nullable karena beberapa flow internal
         * dapat berjalan tanpa user yang terautentikasi secara langsung.
         */
        $operatorUuid = is_string($userUuid) && $userUuid !== ''
            ? $userUuid
            : null;

        /*
         * Validasi input dilakukan sebelum proses dispatch job.
         */
        $payload = $request->validate([
            'recipient' => [
                'required',
                'string',
                'max:150',
            ],
            'body' => [
                'required',
                'string',
                'max:5000',
            ],
            'options' => [
                'nullable',
                'array',
            ],
            'options.title' => [
                'nullable',
                'string',
                'max:200',
            ],
            'options.user_id' => [
                'nullable',
                'string',
                'uuid',
            ],
        ]);

        try {
            /*
             * Dispatch asynchronous notification job.
             *
             * Tenant UUID dan operator UUID diteruskan secara eksplisit
             * agar job tetap tenant-aware ketika dieksekusi oleh worker.
             */
            SendAsynchronousNotificationJob::dispatch(
                $tenantUuid,
                $operatorUuid,
                [
                    'recipient' => $payload['recipient'],
                    'body' => $payload['body'],
                    'options' => $payload['options'] ?? [],
                ]
            )->onConnection('database');

            /*
             * Catat aktivitas ke audit trail setelah job berhasil
             * dijadwalkan.
             */
            $this->auditTrail->log(
                'notification.dispatched',
                sprintf(
                    'Notifikasi berhasil dijadwalkan ke penerima: %s',
                    $payload['recipient']
                ),
                $tenantUuid,
                $operatorUuid,
                [
                    'recipient' => $payload['recipient'],
                    'channel' => 'WHATSAPP',
                ]
            );

            return response()->json([
                'status' => 'success',
                'message' => 'Notification dispatch has been accepted and queued for transmission.',
            ], 202);
        } catch (Throwable $e) {
            /*
             * Jangan expose detail exception internal ke client.
             *
             * Detail exception sebaiknya dicatat melalui logging
             * agar dapat digunakan untuk debugging dan observability.
             */
            report($e);

            return response()->json([
                'status' => 'error',
                'message' => 'Failed to queue notification.',
            ], 500);
        }
    }
}
