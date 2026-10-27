<?php

namespace Modules\HR\Application\Payroll;

use Closure;
use Modules\Core\Application\Settings\ScopedSettingResolver;
use Modules\Core\Application\Settings\SettingDefinition;

/**
 * Aturan berjenjang `hr.attendance_deduction` (PRD-001 §16.2): potongan gaji dari presensi pegawai, per
 * jenjang/lembaga penempatan utama. Nilai = rupiah per hari Alpa, per kali Terlambat, per kali Pulang
 * cepat (masing-masing 0 s.d. 10.000.000). Bawaan semua 0 (tidak ada potongan).
 *
 * @phpstan-type Deduction array{absent: int, late: int, early: int}
 */
final class AttendanceDeductionSetting
{
    public const KEY = 'hr.attendance_deduction';

    public const DEFAULT = ['absent' => 0, 'late' => 0, 'early' => 0];

    public const MAX = 10_000_000;

    public function __construct(
        private readonly ScopedSettingResolver $settings,
    ) {}

    public static function definition(): SettingDefinition
    {
        return new SettingDefinition(
            key: self::KEY,
            label: 'Potongan presensi',
            default: self::DEFAULT,
            rules: ['required', 'array', function (string $attribute, mixed $value, Closure $fail): void {
                $problem = self::problem($value);

                if ($problem !== null) {
                    $fail($problem);
                }
            }],
        );
    }

    public static function problem(mixed $value): ?string
    {
        if (! is_array($value) || count($value) !== 3 || array_diff(array_keys(self::DEFAULT), array_keys($value)) !== []) {
            return 'isi potongan alpa, terlambat, dan pulang cepat.';
        }

        foreach (self::DEFAULT as $field => $default) {
            $amount = $value[$field] ?? null;

            if (! is_int($amount) || $amount < 0 || $amount > self::MAX) {
                return 'potongan Rp 0 s.d. 10.000.000.';
            }
        }

        return null;
    }

    /**
     * @return Deduction
     */
    public static function normalize(mixed $value): array
    {
        if (self::problem($value) !== null || ! is_array($value)) {
            return self::DEFAULT;
        }

        return ['absent' => (int) $value['absent'], 'late' => (int) $value['late'], 'early' => (int) $value['early']];
    }

    /**
     * @param  Deduction  $value
     */
    public static function label(array $value): string
    {
        $rupiah = fn (int $amount): string => 'Rp '.number_format($amount, 0, ',', '.');

        return "Alpa {$rupiah($value['absent'])}/hari · Terlambat {$rupiah($value['late'])}/kali · Pulang cepat {$rupiah($value['early'])}/kali";
    }

    /**
     * @return Deduction
     */
    public function for(string $tenantId, string $organizationId): array
    {
        return self::normalize($this->settings->resolve($tenantId, self::KEY, $organizationId)->value);
    }
}
