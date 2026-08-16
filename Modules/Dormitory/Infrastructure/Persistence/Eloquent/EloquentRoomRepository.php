<?php

declare(strict_types=1);

namespace Modules\Dormitory\Infrastructure\Persistence\Eloquent;

use Modules\Dormitory\Contracts\RoomRepositoryInterface;
use Modules\Dormitory\Models\Bed;
use Modules\Dormitory\Models\Building;
use Modules\Dormitory\Models\Dormitory;
use Modules\Dormitory\Models\Locker;
use Modules\Dormitory\Models\Room;

final class EloquentRoomRepository implements RoomRepositoryInterface
{
    public function findByIdAndTenantForUpdate(
        string $roomId,
        string $tenantId,
    ): ?Room {
        return Room::query()
            ->whereKey($roomId)
            ->where('tenant_id', $tenantId)
            ->lockForUpdate()
            ->first();
    }

    public function findBuildingForUpdate(
        string $buildingId,
        string $tenantId,
    ): ?Building {
        return Building::query()
            ->whereKey($buildingId)
            ->where('tenant_id', $tenantId)
            ->lockForUpdate()
            ->first();
    }

    public function findDormitoryForUpdate(
        string $dormitoryId,
        string $tenantId,
    ): ?Dormitory {
        return Dormitory::query()
            ->whereKey($dormitoryId)
            ->where('tenant_id', $tenantId)
            ->lockForUpdate()
            ->first();
    }

    public function findBedForUpdate(
        string $roomId,
        string $bedId,
        string $tenantId,
    ): ?Bed {
        return Bed::query()
            ->whereKey($bedId)
            ->where('room_id', $roomId)
            ->where('tenant_id', $tenantId)
            ->lockForUpdate()
            ->first();
    }

    public function findLockerForUpdate(
        string $roomId,
        string $lockerId,
        string $tenantId,
    ): ?Locker {
        return Locker::query()
            ->whereKey($lockerId)
            ->where('room_id', $roomId)
            ->where('tenant_id', $tenantId)
            ->lockForUpdate()
            ->first();
    }
}
