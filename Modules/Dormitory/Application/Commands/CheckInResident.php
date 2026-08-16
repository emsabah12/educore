<?php

declare(strict_types=1);

namespace Modules\Dormitory\Application\Commands;

final readonly class CheckInResident
{
    public function __construct(
        public string $membershipId,
        public string $roomId,
        public ?string $bedId,
        public ?string $lockerId,
        public string $residentCategory,
    ) {}
}
