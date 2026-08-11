<?php

declare(strict_types=1);

namespace Modules\Core\Governance\Audit\Contracts;

interface AuditTrailServiceInterface
{
    /**
     * Rekam jejak aktivitas operasional aplikasi ke media penyimpanan immutable.
     *
     * @param array<string, mixed>|null $metadata
     */
    public function log(
        string $eventType,
        string $description,
        ?string $tenantId = null,
        ?string $actorUserId = null,
        ?array $metadata = null,
    ): void;
}
