<?php

declare(strict_types=1);

namespace Modules\Core\Notification\Channels;

use Modules\Core\Contracts\Notification\NotificationChannelInterface;
use Illuminate\Support\Facades\DB;
use Modules\Core\Support\Uuid\UuidV7;
use Illuminate\Support\Facades\Http;
use Throwable;

final class WhatsAppNotificationChannel implements NotificationChannelInterface
{
    /**
     * Mengirimkan notifikasi via WhatsApp Gateway dengan pencatatan status atomik.
     */
    public function send(string $tenantId, string $recipient, string $body, array $options = []): array
    {
        $logId = UuidV7::generate();

        // 1. Catat entri awal dengan status PENDING
        DB::table('notification_logs')->insert([
            'id' => $logId,
            'tenant_id' => $tenantId,
            'user_id' => $options['user_id'] ?? null,
            'recipient' => $recipient,
            'channel' => 'WHATSAPP',
            'title' => $options['title'] ?? null,
            'body' => $body,
            'status' => 'PENDING',
            'created_at' => now(),
            'updated_at' => now()
        ]);

        try {
            // 2. Simulasi Request Outbound Third Party API (Mocking Mechanism)
            // Di lingkungan produksi riil, Anda akan mengganti ini dengan Http::withToken()->post()
            $mockApiResponse = [
                'message_id' => 'msg_' . bin2hex(random_bytes(8)),
                'vendor_status' => 'queued_by_gateway',
                'cost' => 0.002
            ];

            // Simulasikan delay network ringan 50ms
            usleep(50000);

            // 3. Mutasikan status menjadi SENT jika sukses
            DB::table('notification_logs')
                ->where('id', '=', $logId)
                ->update([
                    'status' => 'SENT',
                    'metadata' => json_encode($mockApiResponse),
                    'updated_at' => now()
                ]);

            return [
                'success' => true,
                'log_id' => $logId,
                'metadata' => $mockApiResponse,
                'error' => null
            ];
        } catch (Throwable $e) {
            // 4. Catat kegagalan jika terjadi kendala network vendor
            DB::table('notification_logs')
                ->where('id', '=', $logId)
                ->update([
                    'status' => 'FAILED',
                    'failure_reason' => substr($e->getMessage(), 0, 250),
                    'updated_at' => now()
                ]);

            return [
                'success' => false,
                'log_id' => $logId,
                'metadata' => [],
                'error' => $e->getMessage()
            ];
        }
    }
}
