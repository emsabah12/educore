<?php

namespace Modules\Finance\Application\Invoice;

use Modules\Core\Application\Settings\ScopedSettingResolver;
use Modules\Core\Application\Settings\SettingDefinition;
use Modules\Finance\Application\Authorization\FinanceAccess;

/**
 * Aturan berjenjang `finance.due_day` (PRD-003 §4): tanggal jatuh tempo tagihan bulanan (mis. SPP)
 * di bulan tagihan itu, 1–28 (selalu ada di semua bulan). Bawaan 10. Diubah pemilik
 * finance.settings.manage (Admin Keuangan, Admin Yayasan).
 */
final class DueDaySetting
{
    public const KEY = 'finance.due_day';

    public const DEFAULT = 10;

    public function __construct(
        private readonly ScopedSettingResolver $settings,
    ) {}

    public static function definition(): SettingDefinition
    {
        return new SettingDefinition(
            key: self::KEY,
            label: 'Jatuh tempo tagihan bulanan',
            default: self::DEFAULT,
            rules: ['required', 'integer', 'between:1,28'],
            permission: FinanceAccess::SETTINGS_MANAGE,
        );
    }

    public static function normalize(mixed $value): int
    {
        return is_int($value) && $value >= 1 && $value <= 28 ? $value : self::DEFAULT;
    }

    public static function label(int $day): string
    {
        return "Tanggal {$day} setiap bulan";
    }

    public function for(string $tenantId, string $organizationId): int
    {
        return self::normalize($this->settings->resolve($tenantId, self::KEY, $organizationId)->value);
    }
}
