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

    public const PLACEMENT_PLANNED = 'dormitory.placement_planned';

    public const PLACEMENT_CHECKED_IN = 'dormitory.placement_checked_in';

    public const PLACEMENT_RESOURCE_CHANGED = 'dormitory.placement_resource_changed';

    public const PLACEMENT_CANCELLED = 'dormitory.placement_cancelled';

    public const PLACEMENT_CHECKED_OUT = 'dormitory.placement_checked_out';

    public const SUPERVISOR_ASSIGNED = 'dormitory.supervisor_assigned';

    public const SUPERVISOR_ENDED = 'dormitory.supervisor_ended';

    public const MUTATION_REQUESTED = 'dormitory.mutation_requested';

    public const MUTATION_APPROVED = 'dormitory.mutation_approved';

    public const MUTATION_REJECTED = 'dormitory.mutation_rejected';

    public const MUTATION_CANCELLED = 'dormitory.mutation_cancelled';

    public const MUTATION_EMERGENCY = 'dormitory.mutation_emergency';

    public const MUTATION_REVIEWED = 'dormitory.mutation_reviewed';

    public const YEAR_END_RUN = 'dormitory.year_end_run';

    public const PLACEMENTS_IMPORTED = 'dormitory.placements_imported';

    /** Jenis absen asrama ditambah/diubah/dinonaktifkan/diaktifkan/dihapus (DM-7). */
    public const ATTENDANCE_KIND_CHANGED = 'dormitory.attendance_kind_changed';

    /** Absen asrama satu kamar disimpan atau dikoreksi (DM-7; hanya jumlah per status, tanpa keterangan). */
    public const ATTENDANCE_RECORDED = 'dormitory.attendance_recorded';

    public const SUBJECT_DORMITORY = 'dm_dormitory';

    public const SUBJECT_BUILDING = 'dm_building';

    public const SUBJECT_ROOM = 'dm_room';

    public const SUBJECT_BED = 'dm_bed';

    public const SUBJECT_LOCKER = 'dm_locker';

    public const SUBJECT_PLACEMENT = 'dm_placement';

    public const SUBJECT_SUPERVISOR = 'dm_room_supervisor';

    public const SUBJECT_MUTATION = 'dm_mutation';

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
            self::PLACEMENT_PLANNED => 'Penempatan asrama direncanakan',
            self::PLACEMENT_CHECKED_IN => 'Penghuni asrama check-in',
            self::PLACEMENT_RESOURCE_CHANGED => 'Bed/loker penghuni diganti',
            self::PLACEMENT_CANCELLED => 'Rencana penempatan asrama dibatalkan',
            self::PLACEMENT_CHECKED_OUT => 'Penghuni asrama check-out',
            self::SUPERVISOR_ASSIGNED => 'Wali kamar ditambahkan',
            self::SUPERVISOR_ENDED => 'Penugasan wali kamar diakhiri',
            self::MUTATION_REQUESTED => 'Mutasi kamar diajukan',
            self::MUTATION_APPROVED => 'Mutasi kamar disetujui & dilaksanakan',
            self::MUTATION_REJECTED => 'Mutasi kamar ditolak',
            self::MUTATION_CANCELLED => 'Pengajuan mutasi ditarik',
            self::MUTATION_EMERGENCY => 'Mutasi kamar darurat',
            self::MUTATION_REVIEWED => 'Mutasi darurat ditinjau',
            self::YEAR_END_RUN => 'Proses akhir tahun asrama',
            self::PLACEMENTS_IMPORTED => 'Penempatan diimpor dari file',
            self::ATTENDANCE_KIND_CHANGED => 'Jenis absen asrama diubah',
            self::ATTENDANCE_RECORDED => 'Absen asrama diisi',
        ];
    }
}
