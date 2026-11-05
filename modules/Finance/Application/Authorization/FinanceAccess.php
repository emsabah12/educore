<?php

namespace Modules\Finance\Application\Authorization;

use Modules\Core\Application\Authorization\AccessCatalog;
use Modules\Core\Application\Authorization\CoreAccess;

/**
 * Permission & role modul Finance (PRD-003 §5, PRD-004 §5, PRD-005 §5, PRD-007 §8). Disalin ke database oleh `php artisan educore:sync-access`
 * bersama katalog Core, HR, dan Academic (OD-11).
 */
final class FinanceAccess
{
    /** Lihat tagihan, pembayaran, tunggakan, rekap (sesuai cakupan lembaga). */
    public const BILLING_VIEW = 'finance.billing.view';

    /** Catat & batalkan pembayaran, cetak kuitansi. */
    public const PAYMENTS_MANAGE = 'finance.payments.manage';

    /** Buat & batalkan tagihan, atur potongan siswa. */
    public const INVOICES_MANAGE = 'finance.invoices.manage';

    /** Jenis biaya & tarif — tingkat Yayasan. */
    public const SETTINGS_MANAGE = 'finance.settings.manage';

    /** Lihat kas (buku kas, saldo rekening) — kas lembaga sesuai cakupan; kas Yayasan bila tingkat Yayasan (PRD-004 §5). */
    public const CASH_VIEW = 'finance.cash.view';

    /** Kelola kas: rekening & saldo awal, catat pemasukan, batalkan transaksi (PRD-004 §5). */
    public const CASH_MANAGE = 'finance.cash.manage';

    /** Setujui / tolak pengajuan pengeluaran kas (PRD-004 §4–§5, KS-3); tidak berlaku untuk pengajuan sendiri. */
    public const EXPENSES_APPROVE = 'finance.expenses.approve';

    /** Lihat saldo & riwayat tabungan siswa (PRD-005 §5), sesuai cakupan lembaga. */
    public const SAVINGS_VIEW = 'finance.savings.view';

    /** Catat setoran/penarikan tabungan, batalkan, cetak slip (PRD-005 §5). */
    public const SAVINGS_MANAGE = 'finance.savings.manage';

    /** Lihat pembukuan: jurnal, buku besar, laporan keuangan (PRD-007 §8), sesuai cakupan lembaga. */
    public const LEDGER_VIEW = 'finance.ledger.view';

    /** Kelola pembukuan: COA, pemetaan, pengaturan, susun ulang jurnal, jurnal manual (PRD-007 §8) — tingkat Yayasan. */
    public const LEDGER_MANAGE = 'finance.ledger.manage';

    /** Bendahara lembaga (OD-FN-02): dipasang pada penugasan di lembaga lewat Kelola akses. */
    public const ROLE_BENDAHARA = 'bendahara';

    /** Biro Keuangan tingkat Yayasan (OD-FN-05): terpisah dari Admin SDM agar tidak melihat gaji pegawai. */
    public const ROLE_ADMIN_KEUANGAN = 'admin-keuangan';

    public static function register(AccessCatalog $catalog): void
    {
        $all = [self::BILLING_VIEW, self::PAYMENTS_MANAGE, self::INVOICES_MANAGE, self::SETTINGS_MANAGE, self::CASH_VIEW, self::CASH_MANAGE, self::EXPENSES_APPROVE, self::SAVINGS_VIEW, self::SAVINGS_MANAGE, self::LEDGER_VIEW, self::LEDGER_MANAGE];

        $catalog
            ->permission(self::BILLING_VIEW, 'Lihat tagihan & pembayaran siswa')
            ->permission(self::PAYMENTS_MANAGE, 'Catat pembayaran siswa')
            ->permission(self::INVOICES_MANAGE, 'Kelola tagihan & potongan siswa')
            ->permission(self::SETTINGS_MANAGE, 'Kelola jenis biaya, tarif & kategori kas')
            ->permission(self::CASH_VIEW, 'Lihat kas & saldo')
            ->permission(self::CASH_MANAGE, 'Kelola kas (rekening, pemasukan, pengajuan & realisasi pengeluaran)')
            ->permission(self::EXPENSES_APPROVE, 'Setujui pengajuan pengeluaran')
            ->permission(self::SAVINGS_VIEW, 'Lihat tabungan siswa')
            ->permission(self::SAVINGS_MANAGE, 'Catat setoran & penarikan tabungan siswa')
            ->permission(self::LEDGER_VIEW, 'Lihat pembukuan & laporan keuangan')
            ->permission(self::LEDGER_MANAGE, 'Kelola pembukuan (akun, pemetaan, jurnal)');

        $catalog
            ->role(self::ROLE_BENDAHARA, 'Bendahara', [self::BILLING_VIEW, self::PAYMENTS_MANAGE, self::INVOICES_MANAGE, self::CASH_VIEW, self::CASH_MANAGE, self::SAVINGS_VIEW, self::SAVINGS_MANAGE])
            ->role(self::ROLE_ADMIN_KEUANGAN, 'Admin Keuangan', $all)
            ->grant(CoreAccess::ROLE_ADMIN_YAYASAN, $all)
            ->grant(CoreAccess::ROLE_PIMPINAN, [self::BILLING_VIEW, self::CASH_VIEW, self::EXPENSES_APPROVE, self::SAVINGS_VIEW, self::LEDGER_VIEW])
            ->grant(CoreAccess::ROLE_KEPALA_LEMBAGA, [self::BILLING_VIEW, self::CASH_VIEW, self::EXPENSES_APPROVE, self::SAVINGS_VIEW, self::LEDGER_VIEW])
            ->offerAsTenantRole(self::ROLE_ADMIN_KEUANGAN)
            ->offerAsAssignmentRole(self::ROLE_BENDAHARA);
    }
}
