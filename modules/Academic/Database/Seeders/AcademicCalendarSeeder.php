<?php

namespace Modules\Academic\Database\Seeders;

use Illuminate\Database\Seeder;
use Modules\Academic\Application\Calendar\ManageCalendars;
use Modules\Core\Domain\Tenancy\Tenant;

/**
 * Kalender Yayasan bawaan untuk setiap yayasan (PRD-002 §4.4).
 *
 * Aman dijalankan berulang dan di production: hanya membuat kalender default bila belum ada.
 * Tahun ajaran TIDAK dibuat di sini — tanggalnya diisi admin sesuai keputusan yayasan.
 */
class AcademicCalendarSeeder extends Seeder
{
    public function __construct(
        private readonly ManageCalendars $calendars,
    ) {}

    public function run(): void
    {
        foreach (Tenant::query()->orderBy('code')->pluck('id') as $tenantId) {
            $this->calendars->ensureDefault((string) $tenantId);
        }
    }
}
