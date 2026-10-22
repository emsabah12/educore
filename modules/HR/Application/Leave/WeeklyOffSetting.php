<?php

namespace Modules\HR\Application\Leave;

use Closure;
use Modules\Core\Application\Settings\ScopedSettingResolver;
use Modules\Core\Application\Settings\SettingDefinition;

/**
 * Aturan berjenjang `hr.weekly_off_days` (PRD-001 §13.2, OD-HR-07): hari libur mingguan kalender
 * kerja HR per jenjang/lembaga. Nilai = daftar nomor hari 1 (Senin) – 7 (Ahad), 0–6 hari; bawaan
 * [7] (Ahad). Terpisah dari libur mingguan kalender akademik.
 */
final class WeeklyOffSetting
{
    public const KEY = 'hr.weekly_off_days';

    public const DAYS = [
        1 => 'Senin',
        2 => 'Selasa',
        3 => 'Rabu',
        4 => 'Kamis',
        5 => 'Jumat',
        6 => 'Sabtu',
        7 => 'Ahad',
    ];

    public const DEFAULT = [7];

    public function __construct(
        private readonly ScopedSettingResolver $settings,
    ) {}

    public static function definition(): SettingDefinition
    {
        return new SettingDefinition(
            key: self::KEY,
            label: 'Libur mingguan (kalender kerja)',
            default: self::DEFAULT,
            rules: ['present', 'array', function (string $attribute, mixed $value, Closure $fail): void {
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
        if (! is_array($value) || ! array_is_list($value)) {
            return 'pilih hari dari daftar.';
        }

        foreach ($value as $day) {
            if (! is_int($day) || ! isset(self::DAYS[$day])) {
                return 'pilih hari dari daftar.';
            }
        }

        if (count(array_unique($value)) !== count($value)) {
            return 'hari tidak boleh dipilih dua kali.';
        }

        if (count($value) > 6) {
            return 'sisakan minimal satu hari kerja dalam sepekan.';
        }

        return null;
    }

    /**
     * Rapikan nilai tersimpan: hanya nomor hari sah, unik, urut. Data rusak → bawaan.
     *
     * @return list<int>
     */
    public static function normalize(mixed $value): array
    {
        if (self::problem($value) !== null || ! is_array($value)) {
            return self::DEFAULT;
        }

        $days = array_values(array_filter($value, 'is_int'));
        sort($days);

        return $days;
    }

    /**
     * @param  list<int>  $days
     */
    public static function label(array $days): string
    {
        return $days === [] ? 'Tidak ada' : implode(', ', array_map(fn (int $day): string => self::DAYS[$day] ?? '?', $days));
    }

    /**
     * Libur mingguan yang berlaku di lembaga ini.
     *
     * @return list<int>
     */
    public function daysFor(string $tenantId, string $organizationId): array
    {
        return self::normalize($this->settings->resolve($tenantId, self::KEY, $organizationId)->value);
    }
}
