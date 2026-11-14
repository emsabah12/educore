<?php

namespace Modules\HR\Domain\Ptk;

/**
 * Fungsi PTK sesuai formulir A05 Simpatika: Guru / Staf (PRD-001 §21.3, OD-HR-28 — menggantikan daftar OD-HR-27).
 */
enum PtkFunction: string
{
    case Guru = 'GURU';
    case Staf = 'STAF';

    public function label(): string
    {
        return match ($this) {
            self::Guru => 'Guru',
            self::Staf => 'Staf',
        };
    }
}
