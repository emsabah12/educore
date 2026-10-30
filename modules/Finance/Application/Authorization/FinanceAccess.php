<?php

namespace Modules\Finance\Application\Authorization;

use Modules\Core\Application\Authorization\AccessCatalog;
use Modules\Core\Application\Authorization\CoreAccess;

/**
 * Permission & role modul Finance (PRD-003 §5, PRD-004 §5). Disalin ke database oleh `php artisan educore:sync-access`
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

    /** Bendahara lembaga (OD-FN-02): dipasang pada penugasan di lembaga lewat Kelola akses. */
    public const ROLE_BENDAHARA = 'bendahara';

    /** Biro Keuangan tingkat Yayasan (OD-FN-05): terpisah dari Admin SDM agar tidak melihat gaji pegawai. */
    public const ROLE_ADMIN_KEUANGAN = 'admin-keuangan';

    public static function register(AccessCatalog $catalog): void
    {
        $all = [self::BILLING_VIEW, self::PAYMENTS_MANAGE, self::INVOICES_MANAGE, self::SETTINGS_MANAGE, self::CASH_VIEW, self::CASH_MANAGE];

        $catalog
            ->permission(self::BILLING_VIEW, 'Lihat tagihan & pembayaran siswa')
            ->permission(self::PAYMENTS_MANAGE, 'Catat pembayaran siswa')
            ->permission(self::INVOICES_MANAGE, 'Kelola tagihan & potongan siswa')
            ->permission(self::SETTINGS_MANAGE, 'Kelola jenis biaya, tarif & kategori kas')
            ->permission(self::CASH_VIEW, 'Lihat kas & saldo')
            ->permission(self::CASH_MANAGE, 'Kelola kas (rekening, pemasukan)');

        $catalog
            ->role(self::ROLE_BENDAHARA, 'Bendahara', [self::BILLING_VIEW, self::PAYMENTS_MANAGE, self::INVOICES_MANAGE, self::CASH_VIEW, self::CASH_MANAGE])
            ->role(self::ROLE_ADMIN_KEUANGAN, 'Admin Keuangan', $all)
            ->grant(CoreAccess::ROLE_ADMIN_YAYASAN, $all)
            ->grant(CoreAccess::ROLE_PIMPINAN, [self::BILLING_VIEW, self::CASH_VIEW])
            ->grant(CoreAccess::ROLE_KEPALA_LEMBAGA, [self::BILLING_VIEW, self::CASH_VIEW])
            ->offerAsTenantRole(self::ROLE_ADMIN_KEUANGAN)
            ->offerAsAssignmentRole(self::ROLE_BENDAHARA);
    }
}
