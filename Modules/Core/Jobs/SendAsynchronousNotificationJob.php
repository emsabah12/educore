<?php

declare(strict_types=1);

namespace Modules\Core\Jobs;

use Modules\Core\Jobs\BaseTenantAwareJob;
use Modules\Core\Contracts\Notification\NotificationChannelInterface;
use Exception;

final class SendAsynchronousNotificationJob extends BaseTenantAwareJob
{
    /**
     * Mengeksekusi pengiriman notifikasi di background worker process.
     */
    public function handle(): void
    {
        // Resolusikan driver konkret melalui Service Container internal Laravel
        $channelDriver = app(NotificationChannelInterface::class);

        $recipient = $this->payload['recipient'] ?? null;
        $body = $this->payload['body'] ?? null;
        $options = $this->payload['options'] ?? [];

        if (! $recipient || ! $body) {
            throw new Exception('Payload antrean notifikasi korup. Kehilangan recipient atau konten body.');
        }

        // Jalankan pengiriman aman terisolasi di level tenant context middleware bawaan BaseJob
        $result = $channelDriver->send($this->tenantId, $recipient, $body, $options);

        if (! $result['success']) {
            throw new Exception('Pengiriman notifikasi gagal di level vendor: ' . ($result['error'] ?? 'Unknown Gateway Error'));
        }
    }
}
