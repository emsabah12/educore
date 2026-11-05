<?php

namespace Modules\Finance\Application\Audit;

/**
 * Aksi modul Finance yang dicatat di audit log (PRD-003 §8, PRD-004 §8, PRD-005 §8, PRD-007 §12), dengan label untuk halaman Log audit.
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

    public const EXPENSE_REQUESTED = 'finance.expense_requested';

    public const EXPENSE_CANCELLED = 'finance.expense_cancelled';

    public const EXPENSE_APPROVED = 'finance.expense_approved';

    public const EXPENSE_REJECTED = 'finance.expense_rejected';

    public const EXPENSE_REALIZED = 'finance.expense_realized';

    public const EXPENSE_REALIZATION_VOIDED = 'finance.expense_realization_voided';

    public const CASH_TRANSFER_RECORDED = 'finance.cash_transfer_recorded';

    public const CASH_TRANSFER_VOIDED = 'finance.cash_transfer_voided';

    public const SAVINGS_DEPOSITED = 'finance.savings_deposited';

    public const SAVINGS_WITHDRAWN = 'finance.savings_withdrawn';

    public const SAVINGS_ENTRY_VOIDED = 'finance.savings_entry_voided';

    public const SAVINGS_USED_FOR_PAYMENT = 'finance.savings_used_for_payment';

    public const SUBJECT_SAVINGS_ENTRY = 'fn_savings_entry';

    public const ONLINE_SETTINGS_UPDATED = 'finance.online_settings_updated';

    public const ONLINE_PAYMENT_CREATED = 'finance.online_payment_created';

    public const ONLINE_PAYMENT_PAID = 'finance.online_payment_paid';

    public const ONLINE_PAYMENT_EXPIRED = 'finance.online_payment_expired';

    public const ONLINE_PAYMENT_CANCELLED = 'finance.online_payment_cancelled';

    public const ONLINE_PAYMENT_FAILED = 'finance.online_payment_failed';

    public const ONLINE_OVERPAID_SETTLED = 'finance.online_overpaid_settled';

    public const SUBJECT_ONLINE_PAYMENT = 'fn_online_payment';

    public const LEDGER_ACCOUNT_CREATED = 'finance.ledger_account_created';

    public const LEDGER_ACCOUNT_UPDATED = 'finance.ledger_account_updated';

    public const LEDGER_ACCOUNT_DEACTIVATED = 'finance.ledger_account_deactivated';

    public const LEDGER_ACCOUNT_ACTIVATED = 'finance.ledger_account_activated';

    public const LEDGER_MAPPING_SET = 'finance.ledger_mapping_set';

    public const LEDGER_SETTINGS_SET = 'finance.ledger_settings_set';

    public const JOURNALS_REBUILT = 'finance.journals_rebuilt';

    public const OPENING_BALANCE_SET = 'finance.opening_balance_set';

    public const JOURNAL_POSTED = 'finance.journal_posted';

    public const JOURNAL_VOIDED = 'finance.journal_voided';

    public const SUBJECT_LEDGER_ACCOUNT = 'fn_ledger_account';

    public const SUBJECT_JOURNAL = 'fn_journal';

    /** Pengaturan pembukuan yayasan (subjek = id yayasan). */
    public const SUBJECT_LEDGER_SETTINGS = 'fn_ledger_settings';

    /** Pengaturan pembayaran online yayasan (subjek = id yayasan). */
    public const SUBJECT_ONLINE_SETTINGS = 'fn_online_payment_settings';

    public const SUBJECT_FEE_TYPE = 'fn_fee_type';

    public const SUBJECT_CASH_TRANSFER = 'fn_cash_transfer';

    public const SUBJECT_EXPENSE = 'fn_expense_request';

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
            self::EXPENSE_REQUESTED => 'Pengeluaran diajukan',
            self::EXPENSE_CANCELLED => 'Pengajuan pengeluaran dibatalkan',
            self::EXPENSE_APPROVED => 'Pengeluaran disetujui',
            self::EXPENSE_REJECTED => 'Pengeluaran ditolak',
            self::EXPENSE_REALIZED => 'Pengeluaran direalisasi',
            self::EXPENSE_REALIZATION_VOIDED => 'Realisasi pengeluaran dibatalkan',
            self::CASH_TRANSFER_RECORDED => 'Mutasi kas dicatat',
            self::CASH_TRANSFER_VOIDED => 'Mutasi kas dibatalkan',
            self::SAVINGS_DEPOSITED => 'Setoran tabungan dicatat',
            self::SAVINGS_WITHDRAWN => 'Penarikan tabungan dicatat',
            self::SAVINGS_ENTRY_VOIDED => 'Transaksi tabungan dibatalkan',
            self::SAVINGS_USED_FOR_PAYMENT => 'Tagihan dibayar dari tabungan',
            self::ONLINE_SETTINGS_UPDATED => 'Pengaturan pembayaran online diubah',
            self::ONLINE_PAYMENT_CREATED => 'Pembayaran online dibuat',
            self::ONLINE_PAYMENT_PAID => 'Pembayaran online lunas',
            self::ONLINE_PAYMENT_EXPIRED => 'Pembayaran online kedaluwarsa',
            self::ONLINE_PAYMENT_CANCELLED => 'Pembayaran online dibatalkan',
            self::ONLINE_PAYMENT_FAILED => 'Pembayaran online gagal',
            self::ONLINE_OVERPAID_SETTLED => 'Kelebihan bayar online dikembalikan',
            self::LEDGER_ACCOUNT_CREATED => 'Akun pembukuan ditambahkan',
            self::LEDGER_ACCOUNT_UPDATED => 'Akun pembukuan diubah',
            self::LEDGER_ACCOUNT_DEACTIVATED => 'Akun pembukuan dinonaktifkan',
            self::LEDGER_ACCOUNT_ACTIVATED => 'Akun pembukuan diaktifkan kembali',
            self::LEDGER_MAPPING_SET => 'Pemetaan akun pembukuan diubah',
            self::LEDGER_SETTINGS_SET => 'Pengaturan pembukuan diubah',
            self::JOURNALS_REBUILT => 'Jurnal otomatis disusun ulang',
            self::OPENING_BALANCE_SET => 'Saldo awal pembukuan diubah',
            self::JOURNAL_POSTED => 'Jurnal manual dicatat',
            self::JOURNAL_VOIDED => 'Jurnal manual dibatalkan',
        ];
    }
}
