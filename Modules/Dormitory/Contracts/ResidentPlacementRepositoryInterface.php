<?php

declare(strict_types=1);

namespace Modules\Dormitory\Contracts;

use Modules\Dormitory\Models\ResidentPlacement;

interface ResidentPlacementRepositoryInterface
{
    public function findPlannedForMembershipInRoomForUpdate(
        string $tenantId,
        string $membershipId,
        string $roomId,
        string $residentCategory,
    ): ?ResidentPlacement;

    public function findActiveForMembershipForUpdate(
        string $tenantId,
        string $membershipId,
    ): ?ResidentPlacement;

    public function findActiveForBedForUpdate(
        string $tenantId,
        string $bedId,
    ): ?ResidentPlacement;

    public function save(
        ResidentPlacement $placement,
    ): ResidentPlacement;
}
