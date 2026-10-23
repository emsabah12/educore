<?php

namespace Modules\HR\Contracts;

/**
 * Kontrak libur mingguan kalender akademik, hanya untuk PERBANDINGAN di Pengaturan cuti & presensi
 * (PRD-001 §13.2, OD-HR-07 [OWNER, 2026-10-23: tetap terpisah, tampilkan perbedaannya]). Kalender
 * kerja HR tidak mengikuti nilai ini. Didefinisikan HR, diisi modul Academic.
 */
interface SchoolWeeklyOff
{
    /**
     * Libur mingguan kalender akademik yang dipakai tiap lembaga (1 Senin – 7 Ahad, urut). Lembaga
     * tanpa kalender akademik tidak ada di hasil.
     *
     * @param  list<string>  $organizationIds
     * @return array<string, list<int>>
     */
    public function forOrganizations(string $tenantId, array $organizationIds): array;
}
