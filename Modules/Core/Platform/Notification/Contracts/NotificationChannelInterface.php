<?php

declare(strict_types=1);

namespace Modules\Core\Platform\Notification\Contracts;

interface NotificationChannelInterface
{
    /**
     * Mengirim notifikasi melalui channel yang dipilih.
     *
     * Notification ID dibuat oleh job dan harus tetap sama pada setiap retry.
     * ID tersebut dapat digunakan sebagai idempotency/reference key oleh
     * concrete gateway.
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
        string $notificationId,
        string $recipient,
        string $body,
        array $options = [],
    ): array;
}
