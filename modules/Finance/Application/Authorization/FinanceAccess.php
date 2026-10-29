<?php

namespace Modules\Finance\Application\Authorization;

use Modules\Core\Application\Authorization\AccessCatalog;
use Modules\Core\Application\Authorization\CoreAccess;

/**
 * Permission & role modul Finance (PRD-003 §5). Disalin ke database oleh `php artisan educore:sync-access`
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

    /** Bendahara lembaga (OD-FN-02): dipasang pada penugasan di lembaga lewat Kelola akses. */
    public const ROLE_BENDAHARA = 'bendahara';

    /** Biro Keuangan tingkat Yayasan (OD-FN-05): terpisah dari Admin SDM agar tidak melihat gaji pegawai. */
    public const ROLE_ADMIN_KEUANGAN = 'admin-keuangan';

    public static function register(AccessCatalog $catalog): void
    {
        $all = [self::BILLING_VIEW, self::PAYMENTS_MANAGE, self::INVOICES_MANAGE, self::SETTINGS_MANAGE];

        $catalog
            ->permission(self::BILLING_VIEW, 'Lihat tagihan & pembayaran siswa')
            ->permission(self::PAYMENTS_MANAGE, 'Catat pembayaran siswa')
            ->permission(self::INVOICES_MANAGE, 'Kelola tagihan & potongan siswa')
            ->permission(self::SETTINGS_MANAGE, 'Kelola jenis biaya & tarif');

        $catalog
            ->role(self::ROLE_BENDAHARA, 'Bendahara', [self::BILLING_VIEW, self::PAYMENTS_MANAGE, self::INVOICES_MANAGE])
            ->role(self::ROLE_ADMIN_KEUANGAN, 'Admin Keuangan', $all)
            ->grant(CoreAccess::ROLE_ADMIN_YAYASAN, $all)
            ->grant(CoreAccess::ROLE_PIMPINAN, [self::BILLING_VIEW])
            ->grant(CoreAccess::ROLE_KEPALA_LEMBAGA, [self::BILLING_VIEW])
            ->offerAsTenantRole(self::ROLE_ADMIN_KEUANGAN)
            ->offerAsAssignmentRole(self::ROLE_BENDAHARA);
    }
}
