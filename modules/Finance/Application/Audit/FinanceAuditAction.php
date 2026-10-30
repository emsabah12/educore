<?php

namespace Modules\Finance\Application\Audit;

/**
 * Aksi modul Finance yang dicatat di audit log (PRD-003 §8, PRD-004 §8), dengan label untuk halaman Log audit.
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

    /** Tagihan bulan sesudah siswa keluar dibatalkan otomatis (OD-FN-07, FN-2b). */
    public const INVOICES_VOIDED_ON_EXIT = 'finance.invoices_voided_on_exit';

    public const PAYMENT_RECORDED = 'finance.payment_recorded';

    public const PAYMENT_VOIDED = 'finance.payment_voided';

    public const CASH_CATEGORY_CREATED = 'finance.cash_category_created';

    public const CASH_CATEGORY_UPDATED = 'finance.cash_category_updated';

    public const CASH_CATEGORY_DEACTIVATED = 'finance.cash_category_deactivated';

    public const CASH_CATEGORY_ACTIVATED = 'finance.cash_category_activated';

    public const CASH_ACCOUNT_CREATED = 'finance.cash_account_created';

    public const CASH_ACCOUNT_UPDATED = 'finance.cash_account_updated';

    public const CASH_ENTRY_RECORDED = 'finance.cash_entry_recorded';

    public const CASH_ENTRY_VOIDED = 'finance.cash_entry_voided';

    public const SUBJECT_FEE_TYPE = 'fn_fee_type';

    public const SUBJECT_CASH_CATEGORY = 'fn_cash_category';

    public const SUBJECT_CASH_ACCOUNT = 'fn_cash_account';

    public const SUBJECT_CASH_ENTRY = 'fn_cash_entry';

    public const SUBJECT_PAYMENT = 'fn_payment';

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
            self::INVOICES_VOIDED_ON_EXIT => 'Tagihan dibatalkan otomatis (siswa keluar)',
            self::PAYMENT_RECORDED => 'Pembayaran dicatat',
            self::PAYMENT_VOIDED => 'Pembayaran dibatalkan',
            self::CASH_CATEGORY_CREATED => 'Kategori kas ditambahkan',
            self::CASH_CATEGORY_UPDATED => 'Kategori kas diubah',
            self::CASH_CATEGORY_DEACTIVATED => 'Kategori kas dinonaktifkan',
            self::CASH_CATEGORY_ACTIVATED => 'Kategori kas diaktifkan kembali',
            self::CASH_ACCOUNT_CREATED => 'Rekening kas ditambahkan',
            self::CASH_ACCOUNT_UPDATED => 'Rekening kas diubah',
            self::CASH_ENTRY_RECORDED => 'Transaksi kas dicatat',
            self::CASH_ENTRY_VOIDED => 'Transaksi kas dibatalkan',
        ];
    }
}
