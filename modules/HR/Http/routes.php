<?php

use Illuminate\Support\Facades\Route;
use Modules\HR\Domain\Catalog\CatalogKind;
use Modules\HR\Http\Controllers\CatalogController;
use Modules\HR\Http\Controllers\EmployeeController;

/*
 * Route web modul HR (PRD-001 §6). Dimuat oleh ModuleServiceProvider dengan middleware group "web".
 * Semua halaman HR bekerja di dalam yayasan, jadi wajib memakai `work.context`.
 */
Route::middleware(['auth', 'work.context'])->group(function () {
    $kinds = array_map(fn (CatalogKind $kind): string => $kind->value, CatalogKind::cases());

    // Katalog SDM: ?jenis=jenis-pegawai|status-kepegawaian|jabatan
    Route::get('pegawai/katalog', [CatalogController::class, 'index'])->name('hr.catalog.index');
    Route::post('pegawai/katalog/{kind}', [CatalogController::class, 'store'])->whereIn('kind', $kinds)->name('hr.catalog.store');
    Route::patch('pegawai/katalog/{kind}/{item}', [CatalogController::class, 'update'])->whereIn('kind', $kinds)->name('hr.catalog.update');
    Route::post('pegawai/katalog/{kind}/{item}/nonaktifkan', [CatalogController::class, 'deactivate'])->whereIn('kind', $kinds)->name('hr.catalog.deactivate');
    Route::post('pegawai/katalog/{kind}/{item}/aktifkan', [CatalogController::class, 'activate'])->whereIn('kind', $kinds)->name('hr.catalog.activate');

    // Pegawai (PRD-001 §6). `pegawai/tambah` & `pegawai/katalog` didaftarkan sebelum `pegawai/{employee}`.
    Route::get('pegawai', [EmployeeController::class, 'index'])->name('hr.employees.index');
    Route::get('pegawai/tambah', [EmployeeController::class, 'create'])->name('hr.employees.create');
    Route::post('pegawai', [EmployeeController::class, 'store'])->name('hr.employees.store');
    Route::get('pegawai/{employee}', [EmployeeController::class, 'show'])->whereUuid('employee')->name('hr.employees.show');
});
