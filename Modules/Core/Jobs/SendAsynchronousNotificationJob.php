<?php

declare(strict_types=1);

namespace Modules\Core\Jobs;

use RuntimeException;
use Modules\Core\Support\Uuid\UuidV7;
use Modules\Core\Platform\Notification\Contracts\NotificationChannelInterface;

final class SendAsynchronousNotificationJob extends BaseTenantAwareJob
{
    /**
     * Logical notification identifier.
     *
     * Property ini dibuat sekali ketika job didispatch dan ikut
     * diserialisasi oleh queue, sehingga nilainya stabil pada retry.
     */
    private string $notificationId;

    /**
     * @param array<string, mixed> $payload
     */
    public function __construct(
        string $tenantId,
        ?string $operatorId,
        array $payload = [],
    ) {
        parent::__construct(
            tenantId: $tenantId,
            operatorId: $operatorId,
            payload: $payload,
        );

        $this->notificationId = UuidV7::generate();
    }

    /**
     * Mengeksekusi pengiriman notifikasi di background worker.
     */
    public function handle(): void
    {
        $channel = app(
            NotificationChannelInterface::class,
        );

        $recipient = $this->payload['recipient'] ?? null;
        $body = $this->payload['body'] ?? null;
        $options = $this->payload['options'] ?? [];

        if (
            ! is_string($recipient)
            || trim($recipient) === ''
            || ! is_string($body)
            || trim($body) === ''
        ) {
            throw new RuntimeException(
                'Notification queue payload is invalid.',
            );
        }

        if (! is_array($options)) {
            throw new RuntimeException(
                'Notification queue options are invalid.',
            );
        }

        $result = $channel->send(
            tenantId: $this->tenantId,
            notificationId: $this->notificationId,
            recipient: trim($recipient),
            body: trim($body),
            options: $options,
        );

        if (($result['success'] ?? false) !== true) {
            $error = $result['error'] ?? null;

            throw new RuntimeException(
                is_string($error) && trim($error) !== ''
                    ? trim($error)
                    : 'Notification delivery failed.',
            );
        }
    }

    public function getTenantId(): string
    {
        return $this->tenantId;
    }

    public function getNotificationId(): string
    {
        return $this->notificationId;
    }
}
