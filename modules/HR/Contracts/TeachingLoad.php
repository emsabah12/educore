<?php

namespace Modules\HR\Contracts;

/**
 * Beban mengajar untuk honor JP (PRD-001 §16.2, HR-5b). Didefinisikan HR, diisi modul Academic dari
 * jadwal pelajaran & presensi mengajar (HR tidak bergantung pada Academic). Tanpa Academic dipakai
 * implementasi kosong: tidak ada yang mengajar.
 *
 * Hasil berupa durasi (menit) per pertemuan, dikelompokkan per pegawai → lembaga kelas; HR yang
 * mengubahnya menjadi JP dengan "menit per JP" lembaga itu.
 */
interface TeachingLoad
{
    /**
     * Jam mati: jadwal mingguan yang berlaku pada $date (kelas aktif, tahun ajaran memuat tanggal itu),
     * satu durasi per baris jadwal yang diampu pegawai.
     *
     * @param  list<string>  $employeeIds
     * @return array<string, array<string, list<int>>> pegawai → lembaga → menit per baris jadwal
     */
    public function weekly(string $tenantId, array $employeeIds, string $date): array;

    /**
     * Jam hidup: pertemuan yang terlaksana pada $start..$end — tercatat Mengajar sebagai pengampu, atau
     * Digantikan dengan pegawai itu sebagai pengganti. Pertemuan belum dicatat tidak dihitung.
     *
     * @param  list<string>  $employeeIds
     * @return array<string, array<string, list<int>>> pegawai → lembaga → menit per pertemuan
     */
    public function taught(string $tenantId, array $employeeIds, string $start, string $end): array;
}
