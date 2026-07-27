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
     * Authentication context wajib sudah disediakan oleh
     * InjectTenantContext sebelum request mencapai controller ini.
     *
     * Canonical request context:
     * - authenticated_tenant_id
     * - authenticated_user_id
     */
    public function send(Request $request): JsonResponse
    {
        /*
         * ----------------------------------------------------------------------
         * 1. Resolve Canonical Authentication Context
         * ----------------------------------------------------------------------
         *
         * Context HTTP hanya boleh dibaca dari request attributes
         * yang telah diisi oleh InjectTenantContext.
         *
         * Controller tidak membaca:
         *
         * - X-Tenant-UUID header
         * - tenant_uuid legacy attribute
         * - user_uuid legacy attribute
         * - current_tenant_uuid dari service container
         *
         * Hal ini mencegah client melakukan tenant spoofing melalui header.
         */
        $tenantUuid = $request->attributes->get(
            'authenticated_tenant_id'
        );

        $userUuid = $request->attributes->get(
            'authenticated_user_id'
        );

        /*
         * ----------------------------------------------------------------------
         * 2. Defensive Context Validation
         * ----------------------------------------------------------------------
         *
         * Middleware seharusnya sudah menjamin context tersedia.
         *
         * Check tambahan ini merupakan defense-in-depth apabila controller
         * dipanggil melalui jalur yang tidak melewati middleware yang benar.
         */
        if (! is_string($tenantUuid) || trim($tenantUuid) === '') {
            return response()->json([
                'status' => 'error',
                'message' => 'Unauthorized. Tenant context missing.',
            ], 401);
        }

        $tenantUuid = trim($tenantUuid);

        /*
         * User UUID dapat bersifat nullable untuk flow internal tertentu.
         *
         * Namun jika tersedia, nilainya harus berupa string non-empty.
         */
        $operatorUuid = null;

        if (is_string($userUuid) && trim($userUuid) !== '') {
            $operatorUuid = trim($userUuid);
        }

        /*
         * ----------------------------------------------------------------------
         * 3. Validate Request Payload
         * ----------------------------------------------------------------------
         *
         * Semua input eksternal divalidasi sebelum masuk ke application/job
         * layer.
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
             * ------------------------------------------------------------------
             * 4. Dispatch Tenant-Aware Job
             * ------------------------------------------------------------------
             *
             * Tenant UUID dan operator UUID dikirim secara eksplisit
             * ke asynchronous job.
             *
             * Ini penting karena worker queue tidak boleh bergantung pada
             * HTTP request context yang sudah tidak tersedia.
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
             * ------------------------------------------------------------------
             * 5. Audit Trail
             * ------------------------------------------------------------------
             *
             * Audit dilakukan setelah dispatch berhasil diterima oleh queue.
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

            /*
             * ------------------------------------------------------------------
             * 6. Success Response
             * ------------------------------------------------------------------
             */
            return response()->json([
                'status' => 'success',
                'message' => 'Notification dispatch has been accepted and queued for transmission.',
            ], 202);
        } catch (Throwable $e) {
            /*
             * ------------------------------------------------------------------
             * 7. Error Handling
             * ------------------------------------------------------------------
             *
             * Detail exception internal tidak boleh dikirim ke client.
             *
             * report() digunakan agar exception masuk ke Laravel's
             * configured logging/reporting pipeline.
             */
            report($e);

            return response()->json([
                'status' => 'error',
                'message' => 'Failed to queue notification.',
            ], 500);
        }
    }
}
