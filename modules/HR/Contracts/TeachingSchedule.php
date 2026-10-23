<?php

namespace Modules\HR\Contracts;

/**
 * Kontrak jadwal mengajar untuk presensi pegawai (PRD-001 §14.1, HR-4). Didefinisikan HR, diisi
 * modul Academic dari jadwal pelajaran & libur kalender akademik (HR tidak bergantung pada Academic).
 * Tanpa modul Academic dipakai implementasi kosong: semua pegawai dianggap staf.
 */
interface TeachingSchedule
{
    /**
     * Jam mengajar per tanggal untuk pegawai yang berstatus guru di rentang itu.
     *
     * - Pegawai yang bukan guru pada sebuah tanggal tidak punya entri untuk tanggal itu.
     * - Guru tanpa pelajaran pada tanggal itu (atau libur sekolah) → null.
     * - Selain itu jam pelajaran pertama & terakhir ("HH:MM"), gabungan semua lembaga.
     *
     * @param  list<string>  $employeeIds
     * @return array<string, array<string, array{start: string, end: string}|null>> pegawai → tanggal → jam
     */
    public function windows(string $tenantId, array $employeeIds, string $start, string $end): array;
}
