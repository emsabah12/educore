<?php

declare(strict_types=1);

namespace Modules\Core\Platform\Notification\Contracts;

use Modules\Core\Platform\Notification\DTO\WhatsAppGatewayResult;

interface WhatsAppGatewayInterface
{
    /**
     * Mengirim pesan melalui provider WhatsApp.
     *
     * Notification ID dapat digunakan sebagai idempotency key atau
     * reference ID ketika provider mendukungnya.
     *
     * @param array<string, mixed> $options
     */
    public function send(
        string $tenantId,
        string $notificationId,
        string $recipient,
        string $body,
        array $options = [],
    ): WhatsAppGatewayResult;
}
