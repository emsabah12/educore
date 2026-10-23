<?php

namespace Modules\HR\Application\Attendance;

use Closure;
use Modules\Core\Application\Settings\ScopedSettingResolver;
use Modules\Core\Application\Settings\SettingDefinition;

/**
 * Aturan berjenjang `hr.work_hours` (PRD-001 §14.1, OD-HR-09): jam kerja staf per jenjang/lembaga.
 * Nilai = {start: "HH:MM", end: "HH:MM", tolerance: menit 0–120}; bawaan 07:00–14:00, toleransi 15.
 * Toleransinya juga dipakai guru (jam wajib guru dari jadwal mengajar).
 *
 * @phpstan-type WorkHours array{start: string, end: string, tolerance: int}
 */
final class WorkHoursSetting
{
    public const KEY = 'hr.work_hours';

    public const DEFAULT = ['start' => '07:00', 'end' => '14:00', 'tolerance' => 15];

    public const MAX_TOLERANCE = 120;

    public function __construct(
        private readonly ScopedSettingResolver $settings,
    ) {}

    public static function definition(): SettingDefinition
    {
        return new SettingDefinition(
            key: self::KEY,
            label: 'Jam kerja (presensi pegawai)',
            default: self::DEFAULT,
            rules: ['required', 'array', function (string $attribute, mixed $value, Closure $fail): void {
                $problem = self::problem($value);

                if ($problem !== null) {
                    $fail($problem);
                }
            }],
        );
    }

    /**
     * Pesan kesalahan bila nilai tidak sah, atau null.
     */
    public static function problem(mixed $value): ?string
    {
        if (! is_array($value) || count($value) !== 3 || array_diff(array_keys(self::DEFAULT), array_keys($value)) !== []) {
            return 'isi jam masuk, jam pulang, dan toleransi.';
        }

        $start = $value['start'] ?? null;
        $end = $value['end'] ?? null;
        $tolerance = $value['tolerance'] ?? null;

        if (! is_string($start) || ! is_string($end) || ! self::isTime($start) || ! self::isTime($end)) {
            return 'jam harus berformat JJ:MM, mis. 07:00.';
        }

        if ($end <= $start) {
            return 'jam pulang harus sesudah jam masuk.';
        }

        if (! is_int($tolerance) || $tolerance < 0 || $tolerance > self::MAX_TOLERANCE) {
            return 'toleransi terlambat 0–'.self::MAX_TOLERANCE.' menit.';
        }

        return null;
    }

    /**
     * Nilai tersimpan yang rusak → bawaan.
     *
     * @return WorkHours
     */
    public static function normalize(mixed $value): array
    {
        if (self::problem($value) !== null || ! is_array($value)) {
            return self::DEFAULT;
        }

        return ['start' => (string) $value['start'], 'end' => (string) $value['end'], 'tolerance' => (int) $value['tolerance']];
    }

    /**
     * @param  WorkHours  $hours
     */
    public static function label(array $hours): string
    {
        return str_replace(':', '.', $hours['start']).'–'.str_replace(':', '.', $hours['end']).", toleransi {$hours['tolerance']} menit";
    }

    public static function isTime(string $value): bool
    {
        return preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $value) === 1;
    }

    /**
     * Jam kerja yang berlaku di lembaga ini.
     *
     * @return WorkHours
     */
    public function for(string $tenantId, string $organizationId): array
    {
        return self::normalize($this->settings->resolve($tenantId, self::KEY, $organizationId)->value);
    }
}
