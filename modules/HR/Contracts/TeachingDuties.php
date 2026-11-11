<?php

namespace Modules\HR\Contracts;

/**
 * Tugas mengajar yang masih melekat pada pegawai (PRD-001 §18.2, HR-7b): untuk pemeriksaan otomatis checklist
 * offboarding. Didefinisikan HR, diisi modul Academic (HR tidak bergantung pada Academic). Tanpa Academic → kosong.
 */
interface TeachingDuties
{
    /**
     * Keterangan singkat tugas yang masih tercatat di tahun ajaran yang belum berakhir pada $date,
     * mis. "Wali kelas X-A (MA Unit 1)", "Guru mapel 3 kelas di MA Unit 1".
     *
     * @return list<string>
     */
    public function forEmployee(string $tenantId, string $employeeId, string $date): array;
}
