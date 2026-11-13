<?php

namespace Modules\HR\Application\Attendance;

use Illuminate\Validation\Rule;
use Modules\Core\Application\Settings\ScopedSettingResolver;
use Modules\Core\Application\Settings\SettingDefinition;

/**
 * Aturan berjenjang `hr.attendance_location` (PRD-001 §14.6, HR-4d): apakah absen mandiri memakai lokasi HP.
 * TIDAK = tidak memakai lokasi (bawaan, perilaku HR-4a); CATAT = lokasi dicatat, absen tetap tersimpan;
 * WAJIB = harus di dalam radius lembaga (OD-HR-23).
 */
final class AttendanceLocationSetting
{
    public const KEY = 'hr.attendance_location';

    public const OFF = 'TIDAK';

    public const RECORD = 'CATAT';

    public const REQUIRED = 'WAJIB';

    public const DEFAULT = self::OFF;

    public function __construct(
        private readonly ScopedSettingResolver $settings,
    ) {}

    public static function definition(): SettingDefinition
    {
        return new SettingDefinition(
            key: self::KEY,
            label: 'Lokasi absen pegawai',
            default: self::DEFAULT,
            rules: ['required', 'string', Rule::in([self::OFF, self::RECORD, self::REQUIRED])],
        );
    }

    /**
     * @return list<array{value: string, label: string}>
     */
    public static function options(): array
    {
        return array_map(fn (string $mode): array => ['value' => $mode, 'label' => self::label($mode)], [self::OFF, self::RECORD, self::REQUIRED]);
    }

    public static function label(mixed $mode): string
    {
        return match (self::normalize($mode)) {
            self::RECORD => 'Catat lokasi',
            self::REQUIRED => 'Wajib di dalam radius',
            default => 'Tidak memakai lokasi',
        };
    }

    public static function normalize(mixed $mode): string
    {
        return in_array($mode, [self::OFF, self::RECORD, self::REQUIRED], true) ? $mode : self::DEFAULT;
    }

    public function for(string $tenantId, string $organizationId): string
    {
        return self::normalize($this->settings->resolve($tenantId, self::KEY, $organizationId)->value);
    }
}
