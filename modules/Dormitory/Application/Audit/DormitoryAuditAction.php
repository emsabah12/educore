<?php

namespace Modules\Dormitory\Application\Audit;

/**
 * Aksi modul Dormitory yang dicatat di audit log (PRD-008 §15), dengan label untuk halaman Log audit.
 * Isi catatan bed/loker tidak dicatat; cukup kode & nama isian.
 */
final class DormitoryAuditAction
{
    public const DORMITORY_CREATED = 'dormitory.dormitory_created';

    public const DORMITORY_UPDATED = 'dormitory.dormitory_updated';

    public const DORMITORY_DEACTIVATED = 'dormitory.dormitory_deactivated';

    public const DORMITORY_REACTIVATED = 'dormitory.dormitory_reactivated';

    public const BUILDING_CREATED = 'dormitory.building_created';

    public const BUILDING_UPDATED = 'dormitory.building_updated';

    public const BUILDING_DEACTIVATED = 'dormitory.building_deactivated';

    public const BUILDING_REACTIVATED = 'dormitory.building_reactivated';

    public const ROOM_CREATED = 'dormitory.room_created';

    public const ROOM_UPDATED = 'dormitory.room_updated';

    public const ROOM_DEACTIVATED = 'dormitory.room_deactivated';

    public const ROOM_REACTIVATED = 'dormitory.room_reactivated';

    public const RESOURCES_ADDED = 'dormitory.resources_added';

    public const RESOURCE_UPDATED = 'dormitory.resource_updated';

    public const RESOURCE_DEACTIVATED = 'dormitory.resource_deactivated';

    public const RESOURCE_REACTIVATED = 'dormitory.resource_reactivated';

    public const RESOURCE_DELETED = 'dormitory.resource_deleted';

    public const SUBJECT_DORMITORY = 'dm_dormitory';

    public const SUBJECT_BUILDING = 'dm_building';

    public const SUBJECT_ROOM = 'dm_room';

    public const SUBJECT_BED = 'dm_bed';

    public const SUBJECT_LOCKER = 'dm_locker';

    /**
     * @return array<string, string>
     */
    public static function labels(): array
    {
        return [
            self::DORMITORY_CREATED => 'Asrama ditambahkan',
            self::DORMITORY_UPDATED => 'Asrama diubah',
            self::DORMITORY_DEACTIVATED => 'Asrama dinonaktifkan',
            self::DORMITORY_REACTIVATED => 'Asrama diaktifkan kembali',
            self::BUILDING_CREATED => 'Gedung asrama ditambahkan',
            self::BUILDING_UPDATED => 'Gedung asrama diubah',
            self::BUILDING_DEACTIVATED => 'Gedung asrama dinonaktifkan',
            self::BUILDING_REACTIVATED => 'Gedung asrama diaktifkan kembali',
            self::ROOM_CREATED => 'Kamar asrama ditambahkan',
            self::ROOM_UPDATED => 'Kamar asrama diubah',
            self::ROOM_DEACTIVATED => 'Kamar asrama dinonaktifkan',
            self::ROOM_REACTIVATED => 'Kamar asrama diaktifkan kembali',
            self::RESOURCES_ADDED => 'Bed/loker ditambahkan',
            self::RESOURCE_UPDATED => 'Bed/loker diubah',
            self::RESOURCE_DEACTIVATED => 'Bed/loker dinonaktifkan',
            self::RESOURCE_REACTIVATED => 'Bed/loker diaktifkan kembali',
            self::RESOURCE_DELETED => 'Bed/loker dihapus',
        ];
    }
}
