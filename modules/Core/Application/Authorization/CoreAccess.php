<?php

namespace Modules\Core\Application\Authorization;

/**
 * Permission & role bawaan modul Core (PRD-000 §5, keputusan owner OD-11).
 *
 * Pakai konstanta ini di kode, jangan menulis string key berulang-ulang.
 */
final class CoreAccess
{
    public const ORGANIZATIONS_VIEW = 'core.organizations.view';

    public const ORGANIZATIONS_MANAGE = 'core.organizations.manage';

    public const MEMBERSHIPS_MANAGE = 'core.memberships.manage';

    public const SETTINGS_VIEW = 'core.settings.view';

    public const SETTINGS_MANAGE = 'core.settings.manage';

    public const ROLE_ADMIN_YAYASAN = 'admin-yayasan';

    public const ROLE_PIMPINAN = 'pimpinan';

    public const ROLE_KEPALA_LEMBAGA = 'kepala-lembaga';

    public const ROLE_KOORDINATOR = 'koordinator';

    public const ROLE_STAF = 'staf';

    public static function register(AccessCatalog $catalog): void
    {
        $catalog
            ->permission(self::ORGANIZATIONS_VIEW, 'Lihat lembaga')
            ->permission(self::ORGANIZATIONS_MANAGE, 'Kelola pohon lembaga')
            ->permission(self::MEMBERSHIPS_MANAGE, 'Kelola anggota & penugasan')
            ->permission(self::SETTINGS_VIEW, 'Lihat aturan')
            ->permission(self::SETTINGS_MANAGE, 'Kelola aturan');

        $catalog
            ->role(self::ROLE_ADMIN_YAYASAN, 'Admin Yayasan', [
                self::ORGANIZATIONS_VIEW,
                self::ORGANIZATIONS_MANAGE,
                self::MEMBERSHIPS_MANAGE,
                self::SETTINGS_VIEW,
                self::SETTINGS_MANAGE,
            ])
            ->role(self::ROLE_PIMPINAN, 'Pimpinan', [
                self::ORGANIZATIONS_VIEW,
                self::SETTINGS_VIEW,
            ])
            ->role(self::ROLE_KEPALA_LEMBAGA, 'Kepala Lembaga', [
                self::ORGANIZATIONS_VIEW,
                self::MEMBERSHIPS_MANAGE,
                self::SETTINGS_VIEW,
                self::SETTINGS_MANAGE,
            ])
            ->role(self::ROLE_KOORDINATOR, 'Koordinator', [
                self::ORGANIZATIONS_VIEW,
                self::SETTINGS_VIEW,
                self::SETTINGS_MANAGE,
            ])
            ->role(self::ROLE_STAF, 'Staf', [
                self::ORGANIZATIONS_VIEW,
            ]);
    }
}
