<?php

declare(strict_types=1);

namespace Modules\Core\Notification\Channels;

use Illuminate\Support\Facades\Log;
use Modules\Core\Platform\Notification\Contracts\NotificationAttemptStoreInterface;
use Modules\Core\Platform\Notification\Contracts\NotificationChannelInterface;
use Modules\Core\Platform\Notification\Contracts\WhatsAppGatewayInterface;
use Modules\Core\Platform\Notification\DTO\WhatsAppGatewayResult;
use Throwable;

final readonly class WhatsAppNotificationChannel implements NotificationChannelInterface
{
    private const CHANNEL = 'WHATSAPP';

    public function __construct(
        private WhatsAppGatewayInterface $gateway,
        private NotificationAttemptStoreInterface $attemptStore,
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
        $attempt = $this->attemptStore->prepareAttempt(
            tenantId: $tenantId,
            notificationId: $notificationId,
            userId: $this->optionalString(
                $options['user_id'] ?? null,
            ),
            recipient: $recipient,
            channel: self::CHANNEL,
            title: $this->optionalString(
                $options['title'] ?? null,
            ),
            body: $body,
        );

        /*
         * Redelivery dari durable attempt yang sudah SENT tidak boleh
         * menyebabkan external provider dipanggil untuk kedua kalinya.
         */
        if ($attempt->alreadySent) {
            return [
                'success' => true,
                'log_id' => $notificationId,
                'metadata' => $attempt->metadata,
                'error' => null,
            ];
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

        $this->attemptStore->markSent(
            tenantId: $tenantId,
            notificationId: $notificationId,
            metadata: $gatewayResult->metadata,
        );

        return [
            'success' => true,
            'log_id' => $notificationId,
            'metadata' => $gatewayResult->metadata,
            'error' => null,
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
    private function recordGatewayFailure(
        string $tenantId,
        string $notificationId,
        WhatsAppGatewayResult $result,
    ): array {
        $failureReason = $this->resolveFailureReason(
            $result->failureCode,
        );

        $this->attemptStore->markFailed(
            tenantId: $tenantId,
            notificationId: $notificationId,
            failureReason: $failureReason,
            metadata: $result->metadata,
        );

        Log::warning(
            'WhatsApp gateway rejected notification delivery.',
            [
                'notification_id' => $notificationId,
                'tenant_id' => $tenantId,
                'failure_code' => $result->failureCode
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

        /*
         * Error komunikasi gateway tetap dikembalikan sebagai delivery
         * failure meskipun persistence telemetry ikut gagal.
         *
         * Persistence exception dilaporkan agar observability tetap ada,
         * tetapi tidak mengganti failure utama dari provider.
         */
        try {
            $this->attemptStore->markFailed(
                tenantId: $tenantId,
                notificationId: $notificationId,
                failureReason: $failureReason,
            );
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

    private function optionalString(
        mixed $value,
    ): ?string {
        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);

        return $value !== ''
            ? $value
            : null;
    }
}
