<?php

declare(strict_types=1);

namespace Modules\User\Application\DTO;

final readonly class MembershipSwitchResult
{
    public function __construct(
        public string $membershipId,
        public string $tenantId,
        public string $tenantName,
    ) {}
}
