<?php

namespace Modules\Finance\Application\Cash;

use Modules\Core\Application\Settings\ScopedSettingResolver;
use Modules\Core\Application\Settings\SettingDefinition;
use Modules\Finance\Application\Authorization\FinanceAccess;
use Modules\Finance\Application\Fee\Rupiah;

/**
 * Aturan berjenjang `finance.expense_approval_limit` (PRD-004 §16, KS-5): batas nominal persetujuan pengeluaran kas
 * lembaga. Jumlah disetujui Kepala Lembaga DI ATAS batas → perlu persetujuan kedua tingkat Yayasan. 0 = tanpa batas
 * (bawaan). Diubah pemilik finance.settings.manage (Admin Keuangan, Admin Yayasan).
 */
final class ExpenseApprovalLimitSetting
{
    public const KEY = 'finance.expense_approval_limit';

    public const DEFAULT = 0;

    public function __construct(
        private readonly ScopedSettingResolver $settings,
    ) {}

    public static function definition(): SettingDefinition
    {
        return new SettingDefinition(
            key: self::KEY,
            label: 'Batas persetujuan pengeluaran',
            default: self::DEFAULT,
            rules: ['required', 'integer', 'between:0,'.ManageCashAccounts::MAX_AMOUNT],
            permission: FinanceAccess::SETTINGS_MANAGE,
        );
    }

    public static function normalize(mixed $value): int
    {
        return is_int($value) && $value >= 0 && $value <= ManageCashAccounts::MAX_AMOUNT ? $value : self::DEFAULT;
    }

    public static function label(int $limit): string
    {
        return $limit === 0 ? 'Tanpa batas (cukup Kepala Lembaga)' : 'Di atas '.Rupiah::format($limit).' perlu persetujuan Yayasan';
    }

    public function for(string $tenantId, string $organizationId): int
    {
        return self::normalize($this->settings->resolve($tenantId, self::KEY, $organizationId)->value);
    }
}
