<?php

namespace Modules\Academic\Domain\Attendance;

/**
 * Kode kehadiran siswa (PRD-002 §17) [OWNER, 2026-10-21].
 */
enum AttendanceStatus: string
{
    case Present = 'H';
    case Sick = 'S';
    case Permitted = 'I';
    case Absent = 'A';

    public function label(): string
    {
        return match ($this) {
            self::Present => 'Hadir',
            self::Sick => 'Sakit',
            self::Permitted => 'Izin',
            self::Absent => 'Alpa',
        };
    }

    /**
     * @return list<array{value: string, label: string}>
     */
    public static function options(): array
    {
        $options = [];

        foreach (self::cases() as $case) {
            $options[] = ['value' => $case->value, 'label' => $case->label()];
        }

        return $options;
    }
}
