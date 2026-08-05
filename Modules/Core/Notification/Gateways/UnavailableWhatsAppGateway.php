<?php

declare(strict_types=1);

namespace Modules\Core\Notification\Gateways;

use Modules\Core\Platform\Notification\Contracts\WhatsAppGatewayInterface;
use Modules\Core\Platform\Notification\DTO\WhatsAppGatewayResult;

final class UnavailableWhatsAppGateway implements WhatsAppGatewayInterface
{
    /**
     * @param array<string, mixed> $options
     */
    public function send(
        string $tenantId,
        string $notificationId,
        string $recipient,
        string $body,
        array $options = [],
    ): WhatsAppGatewayResult {
        /*
         * Fail closed sampai adapter provider nyata dikonfigurasi.
         *
         * Jangan mengganti ini dengan simulated success karena sistem
         * akan mencatat pesan sebagai SENT padahal tidak pernah dikirim.
         */
        return WhatsAppGatewayResult::failure(
            'gateway_not_configured',
        );
    }
}
