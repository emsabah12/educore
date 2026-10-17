<?php

namespace Modules\Academic\Application\Calendar;

/**
 * Isian form tahun ajaran beserta semesternya (PRD-002 §4.4). Tanggal berformat Y-m-d;
 * kebenarannya diperiksa SaveAcademicYear.
 */
final readonly class AcademicYearData
{
    /**
     * @param  list<array{name: string, start_date: string, end_date: string}>  $terms  urut semester 1, 2, …
     */
    public function __construct(
        public string $name,
        public string $startDate,
        public string $endDate,
        public array $terms,
    ) {}
}
