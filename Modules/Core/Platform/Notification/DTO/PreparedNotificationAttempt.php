<?php

declare(strict_types=1);

namespace Modules\Core\Platform\Notification\DTO;

final readonly class PreparedNotificationAttempt
{
    /**
     * @param array<string, mixed> $providerMetadata
     */
    public function __construct(
        public bool $alreadySent,
        public array $providerMetadata = [],
    ) {}
}
