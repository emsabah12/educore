<?php

declare(strict_types=1);

namespace Modules\Core\Platform\Notification\Contracts;

use Modules\Core\Platform\Notification\DTO\PreparedNotificationAttempt;

interface NotificationAttemptStoreInterface
{
    /**
     * Membuat durable notification attempt baru atau memulihkan attempt
     * yang sudah ada untuk proses retry.
     *
     * Notification ID adalah identity global. Jika ID yang sama sudah
     * dimiliki tenant atau channel berbeda, implementation wajib menolak
     * operasi sebagai identity collision.
     */
    public function prepareAttempt(
        string $tenantId,
        string $notificationId,
        string $channel,
    ): PreparedNotificationAttempt;

    /**
     * Menandai durable attempt sebagai berhasil terkirim.
     *
     * @param array<string, mixed> $providerMetadata
     */
    public function markSent(
        string $tenantId,
        string $notificationId,
        array $providerMetadata = [],
    ): void;

    /**
     * Menandai durable attempt sebagai gagal.
     *
     * @param array<string, mixed> $providerMetadata
     */
    public function markFailed(
        string $tenantId,
        string $notificationId,
        string $failureCode,
        string $failureReason,
        array $providerMetadata = [],
    ): void;
}
