<?php

declare(strict_types=1);

namespace Modules\Core\Notification\Channels;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Modules\Core\Platform\Notification\Contracts\NotificationChannelInterface;
use Modules\Core\Platform\Notification\Contracts\WhatsAppGatewayInterface;
use Modules\Core\Platform\Notification\DTO\WhatsAppGatewayResult;
use Modules\Core\Support\Uuid\UuidV7;
use Throwable;

final readonly class WhatsAppNotificationChannel implements NotificationChannelInterface
{
    public function __construct(
        private WhatsAppGatewayInterface $gateway,
    ) {}

    /**
     * Mengirim notifikasi WhatsApp dan mencatat lifecycle pengiriman.
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
        $notificationId = UuidV7::generate();

        /*
         * PENDING dibuat terlebih dahulu sebagai durable attempt record.
         *
         * Bila worker mati setelah insert, record ini dapat ditemukan
         * oleh reconciliation atau watchdog pada pengembangan berikutnya.
         */
        DB::table('notification_logs')->insert([
            'id' => $notificationId,
            'tenant_id' => $tenantId,
            'user_id' => $options['user_id'] ?? null,
            'recipient' => $recipient,
            'channel' => 'WHATSAPP',
            'title' => $options['title'] ?? null,
            'body' => $body,
            'status' => 'PENDING',
            'failure_reason' => null,
            'metadata' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        try {
            $gatewayResult = $this->gateway->send(
                tenantId: $tenantId,
                notificationId: $notificationId,
                recipient: $recipient,
                body: $body,
                options: $options,
            );

            if (! $gatewayResult->successful) {
                return $this->recordGatewayFailure(
                    tenantId: $tenantId,
                    notificationId: $notificationId,
                    result: $gatewayResult,
                );
            }

            DB::table('notification_logs')
                ->where('id', $notificationId)
                ->update([
                    'status' => 'SENT',
                    'failure_reason' => null,
                    'metadata' => $this->encodeMetadata(
                        $gatewayResult->metadata,
                    ),
                    'updated_at' => now(),
                ]);

            return [
                'success' => true,
                'log_id' => $notificationId,
                'metadata' => $gatewayResult->metadata,
                'error' => null,
            ];
        } catch (Throwable $exception) {
            /*
             * Raw exception tidak dikembalikan ke job maupun disimpan
             * ke notification_logs.
             */
            Log::error(
                'WhatsApp gateway execution failed unexpectedly.',
                [
                    'notification_id' => $notificationId,
                    'tenant_id' => $tenantId,
                    'exception' => $exception,
                ],
            );

            $failureReason =
                'WhatsApp gateway communication failed.';

            try {
                DB::table('notification_logs')
                    ->where('id', $notificationId)
                    ->update([
                        'status' => 'FAILED',
                        'failure_reason' => $failureReason,
                        'updated_at' => now(),
                    ]);
            } catch (Throwable $persistenceException) {
                report($persistenceException);
            }

            return [
                'success' => false,
                'log_id' => $notificationId,
                'metadata' => [],
                'error' => $failureReason,
            ];
        }
    }

    /**
     * @return array{
     *     success: false,
     *     log_id: string,
     *     metadata: array<string, mixed>,
     *     error: string
     * }
     */
    private function recordGatewayFailure(
        string $tenantId,
        string $notificationId,
        WhatsAppGatewayResult $result,
    ): array {
        $failureReason = $this->resolveFailureReason(
            $result->failureCode,
        );

        DB::table('notification_logs')
            ->where('id', $notificationId)
            ->update([
                'status' => 'FAILED',
                'failure_reason' => $failureReason,
                'metadata' => $this->encodeMetadata(
                    $result->metadata,
                ),
                'updated_at' => now(),
            ]);

        Log::warning(
            'WhatsApp gateway rejected notification delivery.',
            [
                'notification_id' => $notificationId,
                'tenant_id' => $tenantId,
                'failure_code' =>
                $result->failureCode
                    ?? 'unknown_failure',
            ],
        );

        return [
            'success' => false,
            'log_id' => $notificationId,
            'metadata' => $result->metadata,
            'error' => $failureReason,
        ];
    }

    private function resolveFailureReason(
        ?string $failureCode,
    ): string {
        return match ($failureCode) {
            'gateway_not_configured' =>
            'WhatsApp gateway is not configured.',

            'invalid_recipient' =>
            'WhatsApp recipient was rejected by the provider.',

            'provider_rejected' =>
            'WhatsApp provider rejected the delivery request.',

            default =>
            'WhatsApp delivery failed.',
        };
    }

    /**
     * @param array<string, mixed> $metadata
     */
    private function encodeMetadata(
        array $metadata,
    ): ?string {
        if ($metadata === []) {
            return null;
        }

        return json_encode(
            $metadata,
            JSON_THROW_ON_ERROR,
        );
    }
}
