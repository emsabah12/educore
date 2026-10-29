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

    public const DISCOUNT_SET = 'finance.discount_set';

    public const DISCOUNT_REMOVED = 'finance.discount_removed';

    public const INVOICES_GENERATED = 'finance.invoices_generated';

    public const INVOICE_VOIDED = 'finance.invoice_voided';

    public const SUBJECT_FEE_TYPE = 'fn_fee_type';

    public const SUBJECT_INVOICE = 'fn_invoice';

    /** Pendaftaran siswa di lembaga (ac_enrollments) — tagihan & potongan melekat di sini. */
    public const SUBJECT_ENROLLMENT = 'ac_enrollment';

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
            self::DISCOUNT_SET => 'Potongan siswa disimpan',
            self::DISCOUNT_REMOVED => 'Potongan siswa dihapus',
            self::INVOICES_GENERATED => 'Tagihan dibuat',
            self::INVOICE_VOIDED => 'Tagihan dibatalkan',
        ];
    }
}
