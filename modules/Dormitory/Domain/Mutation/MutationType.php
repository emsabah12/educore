<?php

namespace Modules\Dormitory\Domain\Mutation;

/** Jenis mutasi kamar (PRD-008 §8). */
enum MutationType: string
{
    case Regular = 'BIASA';
    case Emergency = 'DARURAT';

    public function label(): string
    {
        return match ($this) {
            self::Regular => 'Biasa',
            self::Emergency => 'Darurat',
        };
    }
}
