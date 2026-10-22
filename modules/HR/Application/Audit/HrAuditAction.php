<?php

namespace Modules\HR\Application\Audit;

/**
 * Aksi modul HR yang dicatat di audit log (PRD-001 §7), dengan label untuk halaman Log audit.
 * Label didaftarkan ke Core lewat AuditAction::registerLabels() di HRServiceProvider.
 */
final class HrAuditAction
{
    public const CATALOG_CREATED = 'hr.catalog_created';

    public const CATALOG_RENAMED = 'hr.catalog_renamed';

    public const CATALOG_DEACTIVATED = 'hr.catalog_deactivated';

    public const CATALOG_ACTIVATED = 'hr.catalog_activated';

    public const EMPLOYEE_CREATED = 'hr.employee_created';

    public const EMPLOYEE_UPDATED = 'hr.employee_updated';

    public const STATUS_CHANGED = 'hr.status_changed';

    public const PLACEMENT_ADDED = 'hr.placement_added';

    public const PLACEMENT_ENDED = 'hr.placement_ended';

    public const PLACEMENT_TRANSFERRED = 'hr.placement_transferred';

    public const PLACEMENT_PRIMARY_CHANGED = 'hr.placement_primary_changed';

    public const EMPLOYMENT_ENDED = 'hr.employment_ended';

    public const EMPLOYEE_REHIRED = 'hr.employee_rehired';

    /** Satu catatan ringkasan per impor; tiap pegawai tetap tercatat EMPLOYEE_CREATED (PRD-001 §6.2). */
    public const EMPLOYEES_IMPORTED = 'hr.employees_imported';

    public const LEAVE_TYPE_CREATED = 'hr.leave_type_created';

    public const LEAVE_TYPE_UPDATED = 'hr.leave_type_updated';

    public const LEAVE_TYPE_DEACTIVATED = 'hr.leave_type_deactivated';

    public const LEAVE_TYPE_REACTIVATED = 'hr.leave_type_reactivated';

    public const HOLIDAY_ADDED = 'hr.holiday_added';

    public const HOLIDAY_UPDATED = 'hr.holiday_updated';

    public const HOLIDAY_REMOVED = 'hr.holiday_removed';

    public const LEAVE_REQUESTED = 'hr.leave_requested';

    public const LEAVE_WITHDRAWN = 'hr.leave_withdrawn';

    public const LEAVE_APPROVED = 'hr.leave_approved';

    public const LEAVE_REJECTED = 'hr.leave_rejected';

    /** Dicatat Admin SDM atas nama pegawai, langsung disetujui (HR-3d). */
    public const LEAVE_RECORDED = 'hr.leave_recorded';

    /** Cuti yang sudah disetujui dibatalkan Admin SDM (HR-3d). */
    public const LEAVE_CANCELLED = 'hr.leave_cancelled';

    /** Jatah cuti disesuaikan Admin SDM (HR-3d-b). */
    public const LEAVE_ADJUSTED = 'hr.leave_adjusted';

    public const SUBJECT_LEAVE_TYPE = 'hr_leave_type';

    public const SUBJECT_HOLIDAY = 'hr_holiday';

    /** Jenis subjek audit untuk data pegawai. */
    public const SUBJECT_EMPLOYEE = 'hr_employee';

    /**
     * @return array<string, string> aksi => label
     */
    public static function labels(): array
    {
        return [
            self::CATALOG_CREATED => 'Katalog SDM ditambahkan',
            self::CATALOG_RENAMED => 'Nama katalog SDM diubah',
            self::CATALOG_DEACTIVATED => 'Katalog SDM dinonaktifkan',
            self::CATALOG_ACTIVATED => 'Katalog SDM diaktifkan kembali',
            self::EMPLOYEE_CREATED => 'Pegawai ditambahkan',
            self::EMPLOYEE_UPDATED => 'Data pegawai diubah',
            self::STATUS_CHANGED => 'Status kepegawaian diganti',
            self::PLACEMENT_ADDED => 'Penempatan ditambahkan',
            self::PLACEMENT_ENDED => 'Penempatan diakhiri',
            self::PLACEMENT_TRANSFERRED => 'Pegawai dimutasi',
            self::PLACEMENT_PRIMARY_CHANGED => 'Penempatan utama diganti',
            self::EMPLOYMENT_ENDED => 'Pegawai diberhentikan',
            self::EMPLOYEE_REHIRED => 'Pegawai diaktifkan kembali',
            self::EMPLOYEES_IMPORTED => 'Pegawai diimpor dari file',
            self::LEAVE_TYPE_CREATED => 'Jenis cuti ditambahkan',
            self::LEAVE_TYPE_UPDATED => 'Jenis cuti diubah',
            self::LEAVE_TYPE_DEACTIVATED => 'Jenis cuti dinonaktifkan',
            self::LEAVE_TYPE_REACTIVATED => 'Jenis cuti diaktifkan kembali',
            self::HOLIDAY_ADDED => 'Hari libur kalender kerja ditambahkan',
            self::HOLIDAY_UPDATED => 'Hari libur kalender kerja diubah',
            self::HOLIDAY_REMOVED => 'Hari libur kalender kerja dihapus',
            self::LEAVE_REQUESTED => 'Cuti diajukan',
            self::LEAVE_WITHDRAWN => 'Pengajuan cuti ditarik',
            self::LEAVE_APPROVED => 'Cuti disetujui',
            self::LEAVE_REJECTED => 'Cuti ditolak',
            self::LEAVE_RECORDED => 'Cuti dicatat atas nama pegawai',
            self::LEAVE_CANCELLED => 'Cuti dibatalkan',
            self::LEAVE_ADJUSTED => 'Jatah cuti disesuaikan',
        ];
    }
}
