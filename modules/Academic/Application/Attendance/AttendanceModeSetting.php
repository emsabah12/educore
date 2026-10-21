<?php

namespace Modules\Academic\Application\Attendance;

use Illuminate\Validation\Rule;
use Modules\Core\Application\Settings\ScopedSettingResolver;
use Modules\Core\Application\Settings\SettingDefinition;

/**
 * Aturan berjenjang `academic.attendance_mode` (PRD-002 §17 AC-4c): presensi HARIAN (oleh wali
 * kelas) atau PER_PERTEMUAN (oleh guru pengampu) per lembaga. Bawaan HARIAN. Mekanisme "terdekat
 * menang, kecuali dikunci" & audit milik Core (PRD-000 §8).
 */
final class AttendanceModeSetting
{
    public const KEY = 'academic.attendance_mode';

    public const DAILY = 'HARIAN';

    public const LESSON = 'PER_PERTEMUAN';

    public function __construct(
        private readonly ScopedSettingResolver $settings,
    ) {}

    public static function definition(): SettingDefinition
    {
        return new SettingDefinition(
            key: self::KEY,
            label: 'Mode presensi',
            default: self::DAILY,
            rules: ['required', 'string', Rule::in([self::DAILY, self::LESSON])],
        );
    }

    public static function label(string $mode): string
    {
        return $mode === self::LESSON ? 'Per pertemuan' : 'Harian';
    }

    /**
     * Mode yang berlaku di lembaga ini.
     */
    public function modeFor(string $tenantId, string $organizationId): string
    {
        return $this->settings->resolve($tenantId, self::KEY, $organizationId)->value === self::LESSON ? self::LESSON : self::DAILY;
    }

    public function isLesson(string $tenantId, string $organizationId): bool
    {
        return $this->modeFor($tenantId, $organizationId) === self::LESSON;
    }
}
