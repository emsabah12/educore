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
        ];
    }
}
