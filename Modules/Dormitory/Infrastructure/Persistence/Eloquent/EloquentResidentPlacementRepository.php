<?php

declare(strict_types=1);

namespace Modules\Dormitory\Infrastructure\Persistence\Eloquent;

use Modules\Dormitory\Contracts\ResidentPlacementRepositoryInterface;
use Modules\Dormitory\Domain\Enums\PlacementStatus;
use Modules\Dormitory\Models\ResidentPlacement;

final class EloquentResidentPlacementRepository implements ResidentPlacementRepositoryInterface
{
    public function findPlannedForMembershipInRoomForUpdate(
        string $tenantId,
        string $membershipId,
        string $roomId,
        string $residentCategory,
    ): ?ResidentPlacement {
        return ResidentPlacement::query()
            ->where('tenant_id', $tenantId)
            ->where('membership_id', $membershipId)
            ->where('room_id', $roomId)
            ->where('resident_category', $residentCategory)
            ->where('status', PlacementStatus::PLANNED->value)
            ->lockForUpdate()
            ->first();
    }

    public function findActiveForMembershipForUpdate(
        string $tenantId,
        string $membershipId,
    ): ?ResidentPlacement {
        return ResidentPlacement::query()
            ->where('tenant_id', $tenantId)
            ->where('membership_id', $membershipId)
            ->where('status', PlacementStatus::ACTIVE->value)
            ->lockForUpdate()
            ->first();
    }

    public function save(
        ResidentPlacement $placement,
    ): ResidentPlacement {
        $placement->saveOrFail();

        return $placement->refresh();
    }
}
