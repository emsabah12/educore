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

    public const CLASS_CREATED = 'academic.class_created';

    public const CLASS_UPDATED = 'academic.class_updated';

    public const CLASS_DEACTIVATED = 'academic.class_deactivated';

    public const CLASS_REACTIVATED = 'academic.class_reactivated';

    public const CLASS_DELETED = 'academic.class_deleted';

    public const CLASS_MEMBERS_ADDED = 'academic.class_members_added';

    public const CLASS_MEMBER_MOVED = 'academic.class_member_moved';

    public const CLASS_MEMBER_REMOVED = 'academic.class_member_removed';

    public const STUDENTS_IMPORTED = 'academic.students_imported';

    public const CLASS_YEAR_END_PROCESSED = 'academic.class_year_end_processed';

    public const SUBJECT_CREATED = 'academic.subject_created';

    public const SUBJECT_UPDATED = 'academic.subject_updated';

    public const SUBJECT_DEACTIVATED = 'academic.subject_deactivated';

    public const SUBJECT_REACTIVATED = 'academic.subject_reactivated';

    public const SUBJECT_DELETED = 'academic.subject_deleted';

    public const CLASS_SUBJECTS_ADDED = 'academic.class_subjects_added';

    public const CLASS_SUBJECT_TEACHER_CHANGED = 'academic.class_subject_teacher_changed';

    public const CLASS_SUBJECT_REMOVED = 'academic.class_subject_removed';

    public const SCHEDULE_ENTRY_ADDED = 'academic.schedule_entry_added';

    public const SCHEDULE_ENTRY_UPDATED = 'academic.schedule_entry_updated';

    public const SCHEDULE_ENTRY_REMOVED = 'academic.schedule_entry_removed';

    public const ATTENDANCE_RECORDED = 'academic.attendance_recorded';

    public const SUBJECT_CALENDAR = 'ac_calendar';

    public const SUBJECT_YEAR = 'ac_academic_year';

    public const SUBJECT_STUDENT = 'ac_student';

    public const SUBJECT_ENROLLMENT = 'ac_enrollment';

    public const SUBJECT_CLASS = 'ac_class';

    public const SUBJECT_SUBJECT = 'ac_subject';

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
            self::CLASS_CREATED => 'Kelas ditambahkan',
            self::CLASS_UPDATED => 'Kelas diubah',
            self::CLASS_DEACTIVATED => 'Kelas dinonaktifkan',
            self::CLASS_REACTIVATED => 'Kelas diaktifkan kembali',
            self::CLASS_DELETED => 'Kelas dihapus',
            self::CLASS_MEMBERS_ADDED => 'Siswa dimasukkan ke kelas',
            self::CLASS_MEMBER_MOVED => 'Siswa dipindah kelas',
            self::CLASS_MEMBER_REMOVED => 'Siswa dikeluarkan dari kelas',
            self::STUDENTS_IMPORTED => 'Siswa diimpor dari file',
            self::CLASS_YEAR_END_PROCESSED => 'Proses akhir tahun kelas',
            self::SUBJECT_CREATED => 'Mata pelajaran ditambahkan',
            self::SUBJECT_UPDATED => 'Mata pelajaran diubah',
            self::SUBJECT_DEACTIVATED => 'Mata pelajaran dinonaktifkan',
            self::SUBJECT_REACTIVATED => 'Mata pelajaran diaktifkan kembali',
            self::SUBJECT_DELETED => 'Mata pelajaran dihapus',
            self::CLASS_SUBJECTS_ADDED => 'Mata pelajaran ditambahkan ke kelas',
            self::CLASS_SUBJECT_TEACHER_CHANGED => 'Guru pengampu diganti',
            self::CLASS_SUBJECT_REMOVED => 'Mata pelajaran dilepas dari kelas',
            self::SCHEDULE_ENTRY_ADDED => 'Jadwal pelajaran ditambahkan',
            self::SCHEDULE_ENTRY_UPDATED => 'Jadwal pelajaran diubah',
            self::SCHEDULE_ENTRY_REMOVED => 'Jadwal pelajaran dihapus',
            self::ATTENDANCE_RECORDED => 'Presensi siswa disimpan',
        ];
    }
}
