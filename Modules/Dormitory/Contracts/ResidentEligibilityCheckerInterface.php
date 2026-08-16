<?php

declare(strict_types=1);

namespace Modules\Dormitory\Contracts;

interface ResidentEligibilityCheckerInterface
{
    public function assertEligible(
        string $tenantId,
        string $membershipId,
        string $residentCategory,
    ): void;
}
