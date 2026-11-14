<?php

namespace Modules\Dormitory\Application\Authorization;

use Modules\Core\Application\Authorization\AccessCatalog;
use Modules\Core\Application\Authorization\CoreAccess;

/**
 * Permission & role modul Dormitory (PRD-008 §7). Disalin ke database oleh `php artisan educore:sync-access`
 * bersama katalog modul lain (OD-11). Wali kamar tidak memakai role: haknya dari data penugasan wali kamar (DM-3,
 * OD-DM-09).
 *
 * DM-1 baru memakai facilities.view|manage; permission lain sudah didaftarkan agar role tidak berubah-ubah
 * di setiap tahap.
 */
final class DormitoryAccess
{
    /** Lihat asrama, gedung, kamar, bed/loker, ketersediaan. */
    public const FACILITIES_VIEW = 'dormitory.facilities.view';

    /** Tambah/ubah/nonaktifkan asrama, gedung, kamar, bed/loker; atur wali kamar. */
    public const FACILITIES_MANAGE = 'dormitory.facilities.manage';

    /** Lihat penghuni, riwayat, laporan hunian, unduh .xlsx (DM-2, DM-6). */
    public const RESIDENTS_VIEW = 'dormitory.residents.view';

    /** Rencanakan, tempatkan, check-in, check-out, batal, impor, proses akhir tahun (DM-2, DM-5). */
    public const RESIDENTS_MANAGE = 'dormitory.residents.manage';

    /** Ajukan mutasi biasa (DM-4). */
    public const MUTATIONS_REQUEST = 'dormitory.mutations.request';

    /** Setujui/tolak mutasi biasa; tinjau mutasi darurat (DM-4). */
    public const MUTATIONS_APPROVE = 'dormitory.mutations.approve';

    /** Eksekusi mutasi darurat (DM-4). */
    public const MUTATIONS_EMERGENCY = 'dormitory.mutations.emergency';

    /** Isi & koreksi absen asrama (DM-7). */
    public const ATTENDANCE_MANAGE = 'dormitory.attendance.manage';

    /** Staf operasional asrama (persona "Admin Asrama" di Fase 1), dipasang pada penugasan lewat Kelola akses. */
    public const ROLE_PENGURUS_ASRAMA = 'pengurus-asrama';

    /** Mudir/Kepala Asrama, dipasang pada penugasan lewat Kelola akses. */
    public const ROLE_KEPALA_ASRAMA = 'kepala-asrama';

    public static function register(AccessCatalog $catalog): void
    {
        $all = [
            self::FACILITIES_VIEW,
            self::FACILITIES_MANAGE,
            self::RESIDENTS_VIEW,
            self::RESIDENTS_MANAGE,
            self::MUTATIONS_REQUEST,
            self::MUTATIONS_APPROVE,
            self::MUTATIONS_EMERGENCY,
            self::ATTENDANCE_MANAGE,
        ];
        $view = [self::FACILITIES_VIEW, self::RESIDENTS_VIEW];

        $catalog
            ->permission(self::FACILITIES_VIEW, 'Lihat fasilitas asrama')
            ->permission(self::FACILITIES_MANAGE, 'Kelola fasilitas asrama & wali kamar')
            ->permission(self::RESIDENTS_VIEW, 'Lihat penghuni asrama')
            ->permission(self::RESIDENTS_MANAGE, 'Kelola penempatan penghuni asrama')
            ->permission(self::MUTATIONS_REQUEST, 'Ajukan mutasi kamar')
            ->permission(self::MUTATIONS_APPROVE, 'Setujui mutasi kamar')
            ->permission(self::MUTATIONS_EMERGENCY, 'Mutasi kamar darurat')
            ->permission(self::ATTENDANCE_MANAGE, 'Isi absen asrama');

        // Matriks Fase 1 owner (PRD-008 §7): pengurus mengajukan & mengeksekusi darurat; kepala asrama menyetujui
        // dan tidak punya izin darurat.
        $catalog
            ->role(self::ROLE_PENGURUS_ASRAMA, 'Pengurus Asrama', [
                self::FACILITIES_VIEW,
                self::FACILITIES_MANAGE,
                self::RESIDENTS_VIEW,
                self::RESIDENTS_MANAGE,
                self::MUTATIONS_REQUEST,
                self::MUTATIONS_EMERGENCY,
                self::ATTENDANCE_MANAGE,
            ])
            ->role(self::ROLE_KEPALA_ASRAMA, 'Kepala Asrama', [self::FACILITIES_VIEW, self::RESIDENTS_VIEW, self::MUTATIONS_APPROVE])
            ->grant(CoreAccess::ROLE_ADMIN_YAYASAN, $all)
            ->grant(CoreAccess::ROLE_PIMPINAN, $view)
            ->grant(CoreAccess::ROLE_KEPALA_LEMBAGA, $view)
            ->grant(CoreAccess::ROLE_KOORDINATOR, $view)
            ->offerAsAssignmentRole(self::ROLE_PENGURUS_ASRAMA)
            ->offerAsAssignmentRole(self::ROLE_KEPALA_ASRAMA);
    }
}
