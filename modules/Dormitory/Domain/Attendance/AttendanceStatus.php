<?php

namespace Modules\Dormitory\Domain\Attendance;

/** Status absen asrama (PRD-008 §12 DM-7, §22). */
enum AttendanceStatus: string
{
    case Present = 'H';
    case Permitted = 'I';
    case Sick = 'S';
    case Missing = 'T';

    public function label(): string
    {
        return match ($this) {
            self::Present => 'Hadir',
            self::Permitted => 'Izin',
            self::Sick => 'Sakit',
            self::Missing => 'Tidak ada',
        };
    }

    /**
     * @return list<array{value: string, label: string}>
     */
    public static function options(): array
    {
        return array_map(fn (self $status): array => ['value' => $status->value, 'label' => $status->label()], self::cases());
    }
}
