<?php

namespace Modules\Dormitory\Application\Facility;

use Modules\Dormitory\Domain\Facility\CapacityBasis;
use Modules\Dormitory\Domain\Facility\RoomComposition;
use Modules\Dormitory\Domain\Facility\YearEndPolicy;

/**
 * Isian form kamar (PRD-008 §4.3–§4.4) yang sudah dirapikan pemanggil.
 */
final readonly class RoomData
{
    public function __construct(
        public string $code,
        public ?string $name,
        public CapacityBasis $capacityBasis,
        public RoomComposition $composition,
        /** Diabaikan (menjadi 0) untuk komposisi Khusus santri. */
        public int $supervisorSlots,
        /** null = ikut bawaan asrama (hanya saat menambah kamar). */
        public ?YearEndPolicy $yearEndPolicy,
    ) {}
}
