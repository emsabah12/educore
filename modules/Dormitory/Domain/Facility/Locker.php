<?php

namespace Modules\Dormitory\Domain\Facility;

/**
 * Loker = satu pintu/kompartemen lemari yang dipakai satu penghuni (PRD-008 §4.4).
 */
class Locker extends RoomResource
{
    protected $table = 'dm_lockers';

    public function kind(): ResourceKind
    {
        return ResourceKind::Locker;
    }
}
