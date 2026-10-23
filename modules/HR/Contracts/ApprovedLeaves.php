<?php

namespace Modules\HR\Contracts;

/**
 * Kontrak publik HR: cuti yang sudah DISETUJUI per pegawai & tanggal (PRD-002 §20.3, AC-6b).
 * Dipakai Academic untuk menandai pertemuan yang perlu guru pengganti. Hanya nama jenis cuti,
 * tanpa alasan.
 */
interface ApprovedLeaves
{
    /**
     * Setiap tanggal di dalam rentang cuti disetujui (termasuk hari libur di dalamnya).
     *
     * @param  list<string>  $employeeIds
     * @return array<string, array<string, string>> pegawai → tanggal (Y-m-d) → nama jenis cuti
     */
    public function byDate(string $tenantId, array $employeeIds, string $start, string $end): array;
}
