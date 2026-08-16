<?php

declare(strict_types=1);

namespace Modules\Dormitory\Contracts;

use Modules\Dormitory\Models\Bed;
use Modules\Dormitory\Models\Building;
use Modules\Dormitory\Models\Dormitory;
use Modules\Dormitory\Models\Locker;
use Modules\Dormitory\Models\Room;

interface RoomRepositoryInterface
{
    public function findByIdAndTenantForUpdate(
        string $roomId,
        string $tenantId,
    ): ?Room;

    public function findBuildingForUpdate(
        string $buildingId,
        string $tenantId,
    ): ?Building;

    public function findDormitoryForUpdate(
        string $dormitoryId,
        string $tenantId,
    ): ?Dormitory;

    public function findBedForUpdate(
        string $roomId,
        string $bedId,
        string $tenantId,
    ): ?Bed;

    public function findLockerForUpdate(
        string $roomId,
        string $lockerId,
        string $tenantId,
    ): ?Locker;
}
