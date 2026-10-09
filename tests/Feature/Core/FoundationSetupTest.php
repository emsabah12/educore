<?php

use Illuminate\Support\Facades\DB;
use Modules\Academic\AcademicServiceProvider;
use Modules\Core\CoreServiceProvider;
use Modules\HR\HRServiceProvider;

/*
 * Kriteria selesai F0 (PRD-000 §10).
 */

test('test berjalan di PostgreSQL, bukan SQLite', function () {
    // Pelajaran dari repo lama (SC-HR-00 RI-003): default SQLite diam-diam
    // menyembunyikan perilaku khusus PostgreSQL seperti partial unique index.
    expect(DB::connection()->getDriverName())->toBe('pgsql');
});

test('provider modul terdaftar', function (string $providerClass) {
    expect(app()->getProvider($providerClass))->not->toBeNull();
})->with([
    'Core' => CoreServiceProvider::class,
    'HR' => HRServiceProvider::class,
    'Academic' => AcademicServiceProvider::class,
]);

test('health check /up merespons 200 tanpa detail dependency', function () {
    $this->get('/up')->assertOk();
});
