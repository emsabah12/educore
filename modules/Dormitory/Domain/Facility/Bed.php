<?php

namespace Modules\Dormitory\Domain\Facility;

/**
 * Bed (tempat tidur) di kamar (PRD-008 §4.4).
 */
class Bed extends RoomResource
{
    protected $table = 'dm_beds';

    public function kind(): ResourceKind
    {
        return ResourceKind::Bed;
    }
}
