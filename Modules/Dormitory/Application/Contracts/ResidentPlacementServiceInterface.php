<?php

declare(strict_types=1);

namespace Modules\Dormitory\Application\Contracts;

use Modules\Dormitory\Application\Commands\CheckInResident;
use Modules\Dormitory\Models\ResidentPlacement;

interface ResidentPlacementServiceInterface
{
    public function checkIn(
        CheckInResident $command,
    ): ResidentPlacement;
}
