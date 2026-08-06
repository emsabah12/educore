<?php

declare(strict_types=1);

namespace Modules\Core\Notification\Channels;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Modules\Core\Platform\Notification\Contracts\NotificationChannelInterface;
use Modules\Core\Platform\Notification\Contracts\WhatsAppGatewayInterface;
use Modules\Core\Platform\Notification\DTO\WhatsAppGatewayResult;
use RuntimeException;
use Throwable;

final readonly class WhatsAppNotificationChannel implements NotificationChannelInterface
{
    public function __construct(
        private WhatsAppGatewayInterface $gateway,
    ) {}

    /**
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
        string $notificationId,
        string $recipient,
        string $body,
        array $options = [],
    ): array {
        /*
         * Membuat atau memulihkan durable attempt record.
         *
         * Bila notification sudah SENT, hasil sebelumnya dikembalikan dan
         * gateway tidak dipanggil lagi.
         */
        $cachedResult = $this->prepareNotificationAttempt(
            tenantId: $tenantId,
            notificationId: $notificationId,
            recipient: $recipient,
            body: $body,
            options: $options,
        );

        if ($cachedResult !== null) {
            return $cachedResult;
        }

        try {
            $gatewayResult = $this->gateway->send(
                tenantId: $tenantId,
                notificationId: $notificationId,
                recipient: $recipient,
                body: $body,
                options: $options,
            );
        } catch (Throwable $exception) {
            return $this->recordUnexpectedGatewayFailure(
                tenantId: $tenantId,
                notificationId: $notificationId,
                exception: $exception,
            );
        }

        if (! $gatewayResult->successful) {
            return $this->recordGatewayFailure(
                tenantId: $tenantId,
                notificationId: $notificationId,
                result: $gatewayResult,
            );
        }

        DB::table('notification_logs')
            ->where('id', $notificationId)
            ->where('tenant_id', $tenantId)
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
    }

    /**
     * Membuat record baru atau memulihkan record pada retry.
     *
     * @param array<string, mixed> $options
     *
     * @return array{
     *     success: true,
     *     log_id: string,
     *     metadata: array<string, mixed>,
     *     error: null
     * }|null
     */
    private function prepareNotificationAttempt(
        string $tenantId,
        string $notificationId,
        string $recipient,
        string $body,
        array $options,
    ): ?array {
        $now = now();

        /*
         * insertOrIgnore menggunakan primary key notification ID untuk
         * memastikan retry tidak membuat row baru.
         */
        DB::table('notification_logs')->insertOrIgnore([
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
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $existingLog = DB::table('notification_logs')
            ->where('id', $notificationId)
            ->first();

        if ($existingLog === null) {
            throw new RuntimeException(
                'Notification attempt could not be persisted.',
            );
        }

        if (
            (string) $existingLog->tenant_id !== $tenantId
            || (string) $existingLog->channel !== 'WHATSAPP'
        ) {
            throw new RuntimeException(
                'Notification identity collision was detected.',
            );
        }

        $status = strtoupper(
            trim((string) $existingLog->status),
        );

        /*
         * Redelivery dari job yang sudah berhasil tidak boleh
         * mengirim pesan ke provider untuk kedua kalinya.
         */
        if ($status === 'SENT') {
            return [
                'success' => true,
                'log_id' => $notificationId,
                'metadata' => $this->decodeMetadata(
                    $existingLog->metadata ?? null,
                ),
                'error' => null,
            ];
        }

        /*
         * Retry dari FAILED/PENDING menggunakan row yang sama.
         */
        DB::table('notification_logs')
            ->where('id', $notificationId)
            ->where('tenant_id', $tenantId)
            ->update([
                'status' => 'PENDING',
                'failure_reason' => null,
                'metadata' => null,
                'updated_at' => $now,
            ]);

        return null;
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
            ->where('tenant_id', $tenantId)
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

    /**
     * @return array{
     *     success: false,
     *     log_id: string,
     *     metadata: array<string, mixed>,
     *     error: string
     * }
     */
    private function recordUnexpectedGatewayFailure(
        string $tenantId,
        string $notificationId,
        Throwable $exception,
    ): array {
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
                ->where('tenant_id', $tenantId)
                ->update([
                    'status' => 'FAILED',
                    'failure_reason' => $failureReason,
                    'metadata' => null,
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

    /**
     * @return array<string, mixed>
     */
    private function decodeMetadata(
        mixed $metadata,
    ): array {
        if (is_array($metadata)) {
            return $metadata;
        }

        if (
            ! is_string($metadata)
            || trim($metadata) === ''
        ) {
            return [];
        }

        try {
            $decoded = json_decode(
                $metadata,
                true,
                512,
                JSON_THROW_ON_ERROR,
            );

            return is_array($decoded)
                ? $decoded
                : [];
        } catch (Throwable $exception) {
            report($exception);

            return [];
        }
    }
}
