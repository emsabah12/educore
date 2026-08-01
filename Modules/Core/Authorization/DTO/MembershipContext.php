<?php

declare(strict_types=1);

namespace Modules\Core\Authorization\DTO;

/**
 * Canonical runtime membership context.
 *
 * Context ini merepresentasikan membership yang telah
 * tervalidasi untuk lifecycle request saat ini.
 *
 * DTO ini tidak memiliki dependency terhadap Eloquent,
 * Repository maupun Service.
 */
final readonly class MembershipContext
{
    public function __construct(
        public string $userId,
        public string $tenantId,
        public string $membershipId,
    ) {}
}
