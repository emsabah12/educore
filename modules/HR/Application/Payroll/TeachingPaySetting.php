<?php

namespace Modules\HR\Application\Payroll;

use Closure;
use Modules\Core\Application\Settings\ScopedSettingResolver;
use Modules\Core\Application\Settings\SettingDefinition;
use Modules\HR\Application\Authorization\HrAccess;

/**
 * Aturan berjenjang `hr.teaching_pay` (PRD-001 §16.2, OD-HR-11): honor mengajar per jenjang/lembaga.
 *
 * Nilai = {mode, minutes, rate}:
 * - mode `JAM_MATI` = JP per minggu dari jadwal × tarif (tidak dikali jumlah minggu);
 *   `JAM_HIDUP` = JP pertemuan yang terlaksana sebulan penuh × tarif [OWNER, 2026-10-27: dipilih per jenjang/lembaga];
 * - minutes = menit per JP (10–120);
 * - rate = tarif rupiah per JP (0 s.d. 10.000.000).
 *
 * Bawaan: jam mati, 45 menit, Rp 0 (belum diatur). Bisa diatur se-yayasan lalu ditimpa per jenjang/lembaga.
 *
 * @phpstan-type TeachingPay array{mode: string, minutes: int, rate: int}
 */
final class TeachingPaySetting
{
    public const KEY = 'hr.teaching_pay';

    public const MODE_FIXED = 'JAM_MATI';

    public const MODE_ACTUAL = 'JAM_HIDUP';

    public const DEFAULT = ['mode' => self::MODE_FIXED, 'minutes' => 45, 'rate' => 0];

    public const MAX_RATE = 10_000_000;

    public function __construct(
        private readonly ScopedSettingResolver $settings,
    ) {}

    public static function definition(): SettingDefinition
    {
        return new SettingDefinition(
            key: self::KEY,
            label: 'Honor mengajar (JP)',
            default: self::DEFAULT,
            rules: ['required', 'array', function (string $attribute, mixed $value, Closure $fail): void {
                $problem = self::problem($value);

                if ($problem !== null) {
                    $fail($problem);
                }
            }],
            // Diubah Admin Yayasan & Admin SDM (Biro SDM dan Keuangan) [OWNER, 2026-10-27].
            permission: HrAccess::PAYROLL_MANAGE,
        );
    }

    public static function problem(mixed $value): ?string
    {
        if (! is_array($value) || count($value) !== 3 || array_diff(array_keys(self::DEFAULT), array_keys($value)) !== []) {
            return 'isi cara hitung, menit per JP, dan tarif per JP.';
        }

        if (! in_array($value['mode'] ?? null, [self::MODE_FIXED, self::MODE_ACTUAL], true)) {
            return 'pilih jam mati atau jam hidup.';
        }

        $minutes = $value['minutes'] ?? null;

        if (! is_int($minutes) || $minutes < 10 || $minutes > 120) {
            return 'menit per JP 10–120.';
        }

        $rate = $value['rate'] ?? null;

        if (! is_int($rate) || $rate < 0 || $rate > self::MAX_RATE) {
            return 'tarif per JP Rp 0 s.d. 10.000.000.';
        }

        return null;
    }

    /**
     * @return TeachingPay
     */
    public static function normalize(mixed $value): array
    {
        if (self::problem($value) !== null || ! is_array($value)) {
            return self::DEFAULT;
        }

        return ['mode' => (string) $value['mode'], 'minutes' => (int) $value['minutes'], 'rate' => (int) $value['rate']];
    }

    public static function modeLabel(string $mode): string
    {
        return $mode === self::MODE_ACTUAL ? 'Jam hidup' : 'Jam mati';
    }

    /**
     * @param  TeachingPay  $value
     */
    public static function label(array $value): string
    {
        return self::modeLabel($value['mode']).' · '.$value['minutes'].' mnt/JP · Rp '.number_format($value['rate'], 0, ',', '.').'/JP';
    }

    /**
     * @return TeachingPay
     */
    public function for(string $tenantId, string $organizationId): array
    {
        return self::normalize($this->settings->resolve($tenantId, self::KEY, $organizationId)->value);
    }
}
