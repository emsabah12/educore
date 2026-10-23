<?php

namespace Modules\Academic\Application\Authorization;

use Modules\Core\Application\Authorization\AccessCatalog;
use Modules\Core\Application\Authorization\CoreAccess;

/**
 * Permission & role modul Academic (PRD-002 §5.1–§5.2). Disalin ke database oleh
 * `php artisan educore:sync-access` bersama katalog Core & HR (OD-11).
 *
 * Menjadi wali kelas TIDAK memberi permission apa pun; hak akses hanya dari role.
 */
final class AcademicAccess
{
    /** Daftar & detail siswa dan kelas (tanpa data pribadi). */
    public const STUDENTS_VIEW = 'academic.students.view';

    /** Data pribadi siswa + data orang tua/wali (anak di bawah umur, PRD-002 §4.5). */
    public const STUDENTS_VIEW_PERSONAL = 'academic.students.view_personal';

    /** Tambah siswa, ubah profil, pendaftaran, orang tua/wali. */
    public const STUDENTS_MANAGE = 'academic.students.manage';

    /** Kelas/rombel, wali kelas, anggota kelas. */
    public const CLASSES_MANAGE = 'academic.classes.manage';

    /** Presensi mengajar & jadwal piket di lembaga (AC-6, PRD-002 §20). */
    public const TEACHING_MANAGE = 'academic.teaching.manage';

    /** Kalender, tahun ajaran, semester — hanya di tingkat Yayasan. */
    public const CALENDAR_MANAGE = 'academic.calendar.manage';

    /** Operator/TU lembaga yang mengelola data siswa & kelas di lembaganya (OD-AC-04). */
    public const ROLE_OPERATOR_AKADEMIK = 'operator-akademik';

    public static function register(AccessCatalog $catalog): void
    {
        $catalog
            ->permission(self::STUDENTS_VIEW, 'Lihat data siswa & kelas')
            ->permission(self::STUDENTS_VIEW_PERSONAL, 'Lihat data pribadi siswa & orang tua')
            ->permission(self::STUDENTS_MANAGE, 'Kelola data siswa')
            ->permission(self::CLASSES_MANAGE, 'Kelola kelas')
            ->permission(self::TEACHING_MANAGE, 'Kelola presensi mengajar & jadwal piket')
            ->permission(self::CALENDAR_MANAGE, 'Kelola kalender akademik');

        $view = [self::STUDENTS_VIEW, self::STUDENTS_VIEW_PERSONAL];

        $catalog
            ->role(self::ROLE_OPERATOR_AKADEMIK, 'Operator Akademik', [
                ...$view,
                self::STUDENTS_MANAGE,
                self::CLASSES_MANAGE,
                self::TEACHING_MANAGE,
            ])
            ->grant(CoreAccess::ROLE_ADMIN_YAYASAN, [
                ...$view,
                self::STUDENTS_MANAGE,
                self::CLASSES_MANAGE,
                self::TEACHING_MANAGE,
                self::CALENDAR_MANAGE,
            ])
            ->grant(CoreAccess::ROLE_PIMPINAN, $view)
            ->grant(CoreAccess::ROLE_KEPALA_LEMBAGA, [...$view, self::TEACHING_MANAGE])
            ->grant(CoreAccess::ROLE_KOORDINATOR, [self::STUDENTS_VIEW])
            // Dipasang pada penugasan di lembaga (atau unit) lewat halaman Kelola akses.
            ->offerAsAssignmentRole(self::ROLE_OPERATOR_AKADEMIK);
    }
}
