<?php

namespace Modules\HR\Application\Authorization;

use Modules\Core\Application\Authorization\AccessCatalog;
use Modules\Core\Application\Authorization\CoreAccess;

/**
 * Permission & role modul HR (PRD-001 §5.1–§5.2). Disalin ke database oleh
 * `php artisan educore:sync-access` bersama katalog Core (OD-11).
 *
 * Jabatan di data pegawai TIDAK memberi permission apa pun; hak akses hanya dari role.
 */
final class HrAccess
{
    /** Daftar & detail pegawai: data kepegawaian (kelas INTERNAL, [LAMA] HR-014 §4). */
    public const EMPLOYEES_VIEW = 'hr.employees.view';

    /** Data pribadi pegawai: tempat/tanggal lahir, HP, alamat (kelas CONFIDENTIAL). */
    public const EMPLOYEES_VIEW_PERSONAL = 'hr.employees.view_personal';

    /** Tambah & ubah pegawai, mutasi, berhenti (OD-HR-02: hanya Admin SDM pusat). */
    public const EMPLOYEES_MANAGE = 'hr.employees.manage';

    /** Kelola katalog SDM, hanya di tingkat Yayasan. */
    public const CATALOG_MANAGE = 'hr.catalog.manage';

    public const ROLE_ADMIN_SDM = 'admin-sdm';

    public static function register(AccessCatalog $catalog): void
    {
        $catalog
            ->permission(self::EMPLOYEES_VIEW, 'Lihat data pegawai')
            ->permission(self::EMPLOYEES_VIEW_PERSONAL, 'Lihat data pribadi pegawai')
            ->permission(self::EMPLOYEES_MANAGE, 'Kelola data pegawai')
            ->permission(self::CATALOG_MANAGE, 'Kelola katalog SDM');

        $all = [
            self::EMPLOYEES_VIEW,
            self::EMPLOYEES_VIEW_PERSONAL,
            self::EMPLOYEES_MANAGE,
            self::CATALOG_MANAGE,
        ];

        $catalog
            ->role(self::ROLE_ADMIN_SDM, 'Admin SDM', $all)
            // [ASUMSI] Admin Yayasan ikut mengelola HR selama halaman kelola akses belum ada (OD-HR-03).
            ->grant(CoreAccess::ROLE_ADMIN_YAYASAN, $all)
            ->grant(CoreAccess::ROLE_PIMPINAN, [self::EMPLOYEES_VIEW, self::EMPLOYEES_VIEW_PERSONAL])
            ->grant(CoreAccess::ROLE_KEPALA_LEMBAGA, [self::EMPLOYEES_VIEW, self::EMPLOYEES_VIEW_PERSONAL])
            ->grant(CoreAccess::ROLE_KOORDINATOR, [self::EMPLOYEES_VIEW])
            // Admin SDM bekerja di tingkat Yayasan (OD-HR-02); dipasang dari halaman kelola akses.
            ->offerAsTenantRole(self::ROLE_ADMIN_SDM);
    }
}
