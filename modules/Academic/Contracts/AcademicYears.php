<?php

namespace Modules\Academic\Contracts;

/**
 * Kontrak publik Academic untuk modul lain (mis. Finance, PRD-003 §7): tahun ajaran yang berlaku di
 * sebuah lembaga, yaitu tahun ajaran milik kalender akademik yang dipakai lembaga itu (PRD-002 §4.4).
 *
 * @phpstan-type SchoolYear array{id: string, name: string, start_date: string, end_date: string, is_current: bool}
 */
interface AcademicYears
{
    /**
     * Tahun ajaran kalender lembaga, terbaru dulu. Lembaga tanpa kalender → [].
     *
     * @return list<SchoolYear>
     */
    public function forOrganization(string $tenantId, string $organizationId): array;
}
