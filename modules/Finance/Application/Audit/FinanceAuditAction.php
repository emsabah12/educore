<?php

namespace Modules\Finance\Application\Audit;

/**
 * Aksi modul Finance yang dicatat di audit log (PRD-003 §8), dengan label untuk halaman Log audit.
 */
final class FinanceAuditAction
{
    public const FEE_TYPE_CREATED = 'finance.fee_type_created';

    public const FEE_TYPE_UPDATED = 'finance.fee_type_updated';

    public const FEE_TYPE_DEACTIVATED = 'finance.fee_type_deactivated';

    public const FEE_TYPE_ACTIVATED = 'finance.fee_type_activated';

    public const FEE_RATE_SET = 'finance.fee_rate_set';

    public const FEE_RATE_REMOVED = 'finance.fee_rate_removed';

    public const SUBJECT_FEE_TYPE = 'fn_fee_type';

    /**
     * @return array<string, string>
     */
    public static function labels(): array
    {
        return [
            self::FEE_TYPE_CREATED => 'Jenis biaya ditambahkan',
            self::FEE_TYPE_UPDATED => 'Jenis biaya diubah',
            self::FEE_TYPE_DEACTIVATED => 'Jenis biaya dinonaktifkan',
            self::FEE_TYPE_ACTIVATED => 'Jenis biaya diaktifkan kembali',
            self::FEE_RATE_SET => 'Tarif biaya disimpan',
            self::FEE_RATE_REMOVED => 'Tarif biaya dihapus',
        ];
    }
}
