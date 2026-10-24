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

    /** Menyetujui cuti tahap lembaga (PRD-001 §13, HR-3c), di cakupan penugasannya. */
    public const LEAVE_APPROVE = 'hr.leave.approve';

    /** Jenis & jatah cuti, kalender kerja, tahap SDM, catat atas nama, batalkan, rekap (tingkat Yayasan). */
    public const LEAVE_MANAGE = 'hr.leave.manage';

    /** Lembar presensi pegawai per lembaga & tanggal (PRD-001 §14.3, HR-4b), di cakupan penugasannya. */
    public const ATTENDANCE_MANAGE = 'hr.attendance.manage';

    public const ROLE_ADMIN_SDM = 'admin-sdm';

    /** Petugas presensi lembaga (HR-4b): hanya mengisi lembar presensi pegawai lembaganya. */
    public const ROLE_PETUGAS_PRESENSI = 'petugas-presensi';

    public static function register(AccessCatalog $catalog): void
    {
        $catalog
            ->permission(self::EMPLOYEES_VIEW, 'Lihat data pegawai')
            ->permission(self::EMPLOYEES_VIEW_PERSONAL, 'Lihat data pribadi pegawai')
            ->permission(self::EMPLOYEES_MANAGE, 'Kelola data pegawai')
            ->permission(self::CATALOG_MANAGE, 'Kelola katalog SDM')
            ->permission(self::LEAVE_APPROVE, 'Setujui cuti pegawai lembaga')
            ->permission(self::LEAVE_MANAGE, 'Kelola cuti pegawai')
            ->permission(self::ATTENDANCE_MANAGE, 'Kelola presensi pegawai');

        $all = [
            self::EMPLOYEES_VIEW,
            self::EMPLOYEES_VIEW_PERSONAL,
            self::EMPLOYEES_MANAGE,
            self::CATALOG_MANAGE,
            self::LEAVE_APPROVE,
            self::LEAVE_MANAGE,
            self::ATTENDANCE_MANAGE,
        ];

        $catalog
            ->role(self::ROLE_ADMIN_SDM, 'Admin SDM', $all)
            // [ASUMSI] Admin Yayasan ikut mengelola HR selama halaman kelola akses belum ada (OD-HR-03).
            ->grant(CoreAccess::ROLE_ADMIN_YAYASAN, $all)
            ->grant(CoreAccess::ROLE_PIMPINAN, [self::EMPLOYEES_VIEW, self::EMPLOYEES_VIEW_PERSONAL, self::LEAVE_APPROVE])
            // Kepala Lembaga = penyetuju cuti tahap lembaga (PRD-001 §13.4).
            ->grant(CoreAccess::ROLE_KEPALA_LEMBAGA, [self::EMPLOYEES_VIEW, self::EMPLOYEES_VIEW_PERSONAL, self::LEAVE_APPROVE, self::ATTENDANCE_MANAGE])
            // [ASUMSI, PRD-001 §14.3] Petugas presensi dipasang per lembaga lewat Kelola akses.
            ->role(self::ROLE_PETUGAS_PRESENSI, 'Petugas Presensi', [self::ATTENDANCE_MANAGE])
            ->grant(CoreAccess::ROLE_KOORDINATOR, [self::EMPLOYEES_VIEW])
            // Admin SDM bekerja di tingkat Yayasan (OD-HR-02); dipasang dari halaman kelola akses.
            ->offerAsTenantRole(self::ROLE_ADMIN_SDM)
            ->offerAsAssignmentRole(self::ROLE_PETUGAS_PRESENSI);
    }
}
