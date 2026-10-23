<?php

namespace Modules\HR\Domain\Attendance;

/**
 * Status presensi pegawai per hari (PRD-001 §14.1). Cuti bukan status presensi: ditampilkan dari
 * data cuti yang disetujui.
 */
enum AttendanceStatus: string
{
    case Present = 'HADIR';
    case Sick = 'SAKIT';
    case Permit = 'IZIN';
    case Absent = 'ALPA';
    case Duty = 'DINAS_LUAR';

    public function label(): string
    {
        return match ($this) {
            self::Present => 'Hadir',
            self::Sick => 'Sakit',
            self::Permit => 'Izin',
            self::Absent => 'Alpa',
            self::Duty => 'Dinas luar',
        };
    }

    /** Status yang boleh punya jam masuk/pulang. */
    public function hasTimes(): bool
    {
        return $this === self::Present || $this === self::Duty;
    }
}
