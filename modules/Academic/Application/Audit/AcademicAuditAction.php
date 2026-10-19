<?php

namespace Modules\Academic\Application\Audit;

/**
 * Aksi modul Academic yang dicatat di audit log (PRD-002 §8), dengan label untuk halaman Log audit.
 * Label didaftarkan ke Core lewat AuditAction::registerLabels() di AcademicServiceProvider.
 *
 * Perubahan pemakaian kalender (aturan `academic.calendar`) dicatat Core sebagai perubahan aturan.
 */
final class AcademicAuditAction
{
    public const CALENDAR_CREATED = 'academic.calendar_created';

    public const CALENDAR_RENAMED = 'academic.calendar_renamed';

    public const YEAR_CREATED = 'academic.year_created';

    public const YEAR_UPDATED = 'academic.year_updated';

    public const YEAR_SET_CURRENT = 'academic.year_set_current';

    public const STUDENT_CREATED = 'academic.student_created';

    public const ENROLLMENT_CREATED = 'academic.enrollment_created';

    public const GUARDIAN_LINKED = 'academic.guardian_linked';

    public const STUDENT_UPDATED = 'academic.student_updated';

    public const ENROLLMENT_UPDATED = 'academic.enrollment_updated';

    public const ENROLLMENT_CLOSED = 'academic.enrollment_closed';

    public const GUARDIAN_UPDATED = 'academic.guardian_updated';

    public const GUARDIAN_UNLINKED = 'academic.guardian_unlinked';

    public const PRIMARY_CONTACT_CHANGED = 'academic.primary_contact_changed';

    public const SUBJECT_CALENDAR = 'ac_calendar';

    public const SUBJECT_YEAR = 'ac_academic_year';

    public const SUBJECT_STUDENT = 'ac_student';

    public const SUBJECT_ENROLLMENT = 'ac_enrollment';

    /**
     * @return array<string, string> aksi => label
     */
    public static function labels(): array
    {
        return [
            self::CALENDAR_CREATED => 'Kalender akademik ditambahkan',
            self::CALENDAR_RENAMED => 'Nama kalender akademik diubah',
            self::YEAR_CREATED => 'Tahun ajaran ditambahkan',
            self::YEAR_UPDATED => 'Tahun ajaran diubah',
            self::YEAR_SET_CURRENT => 'Tahun ajaran berjalan diganti',
            self::STUDENT_CREATED => 'Siswa ditambahkan',
            self::ENROLLMENT_CREATED => 'Siswa didaftarkan ke lembaga',
            self::GUARDIAN_LINKED => 'Orang tua/wali ditautkan',
            self::STUDENT_UPDATED => 'Data diri siswa diubah',
            self::ENROLLMENT_UPDATED => 'Pendaftaran siswa diubah',
            self::ENROLLMENT_CLOSED => 'Pendaftaran siswa ditutup',
            self::GUARDIAN_UPDATED => 'Data orang tua/wali diubah',
            self::GUARDIAN_UNLINKED => 'Orang tua/wali dilepas dari siswa',
            self::PRIMARY_CONTACT_CHANGED => 'Kontak utama siswa diganti',
        ];
    }
}
