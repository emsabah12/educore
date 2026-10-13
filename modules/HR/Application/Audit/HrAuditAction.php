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
        ];
    }
}
