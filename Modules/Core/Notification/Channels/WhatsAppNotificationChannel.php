<?php

declare(strict_types=1);

namespace Modules\Core\Notification\Channels;

use Illuminate\Support\Facades\DB;
use Modules\Core\Platform\Notification\Contracts\NotificationChannelInterface;
use Modules\Core\Support\Uuid\UuidV7;
use Throwable;

final class WhatsAppNotificationChannel implements NotificationChannelInterface
{
    /**
     * Mengirim notifikasi WhatsApp dan mencatat status pengiriman.
     *
     * @param array<string, mixed> $options
     *
     * @return array{
     *     success: bool,
     *     log_id: string,
     *     metadata: array<string, mixed>,
     *     error: string|null
     * }
     */
    public function send(
        string $tenantId,
        string $recipient,
        string $body,
        array $options = [],
    ): array {
        $logId = UuidV7::generate();

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
            'updated_at' => now(),
        ]);

        try {
            /*
             * Simulasi sementara respons outbound gateway.
             *
             * Implementasi production nantinya dapat diganti dengan
             * HTTP client tanpa mengubah contract notification channel.
             */
            $gatewayResponse = [
                'message_id' => sprintf(
                    'msg_%s',
                    bin2hex(random_bytes(8)),
                ),
                'vendor_status' => 'queued_by_gateway',
                'cost' => 0.002,
            ];

            DB::table('notification_logs')
                ->where('id', $logId)
                ->update([
                    'status' => 'SENT',
                    'metadata' => json_encode(
                        $gatewayResponse,
                        JSON_THROW_ON_ERROR,
                    ),
                    'updated_at' => now(),
                ]);

            return [
                'success' => true,
                'log_id' => $logId,
                'metadata' => $gatewayResponse,
                'error' => null,
            ];
        } catch (Throwable $exception) {
            DB::table('notification_logs')
                ->where('id', $logId)
                ->update([
                    'status' => 'FAILED',
                    'failure_reason' => mb_substr(
                        $exception->getMessage(),
                        0,
                        250,
                    ),
                    'updated_at' => now(),
                ]);

            return [
                'success' => false,
                'log_id' => $logId,
                'metadata' => [],
                'error' => $exception->getMessage(),
            ];
        }
    }
}
