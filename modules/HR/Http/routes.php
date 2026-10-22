<?php

use Illuminate\Support\Facades\Route;
use Modules\HR\Domain\Catalog\CatalogKind;
use Modules\HR\Http\Controllers\CatalogController;
use Modules\HR\Http\Controllers\EmployeeActionController;
use Modules\HR\Http\Controllers\EmployeeController;
use Modules\HR\Http\Controllers\EmployeeImportController;
use Modules\HR\Http\Controllers\LeaveSettingsController;

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

    // Pengaturan cuti (PRD-001 §13.2, HR-3a): jenis cuti, libur mingguan, hari libur. Admin SDM / Admin Yayasan.
    Route::get('pegawai/cuti/pengaturan', [LeaveSettingsController::class, 'index'])->name('hr.leave-settings.index');
    Route::post('pegawai/cuti/jenis', [LeaveSettingsController::class, 'storeType'])->name('hr.leave-types.store');
    Route::patch('pegawai/cuti/jenis/{type}', [LeaveSettingsController::class, 'updateType'])->whereUuid('type')->name('hr.leave-types.update');
    Route::post('pegawai/cuti/jenis/{type}/nonaktifkan', [LeaveSettingsController::class, 'deactivateType'])->whereUuid('type')->name('hr.leave-types.deactivate');
    Route::post('pegawai/cuti/jenis/{type}/aktifkan', [LeaveSettingsController::class, 'activateType'])->whereUuid('type')->name('hr.leave-types.activate');
    Route::post('pegawai/cuti/libur-mingguan', [LeaveSettingsController::class, 'assignWeeklyOff'])->name('hr.weekly-off.assign');
    Route::post('pegawai/cuti/hari-libur', [LeaveSettingsController::class, 'storeHoliday'])->name('hr.holidays.store');
    Route::patch('pegawai/cuti/hari-libur/{holiday}', [LeaveSettingsController::class, 'updateHoliday'])->whereUuid('holiday')->name('hr.holidays.update');
    Route::delete('pegawai/cuti/hari-libur/{holiday}', [LeaveSettingsController::class, 'destroyHoliday'])->whereUuid('holiday')->name('hr.holidays.destroy');

    // Pegawai (PRD-001 §6). `pegawai/tambah`, `pegawai/impor` & `pegawai/katalog` didaftarkan sebelum `pegawai/{employee}`.
    Route::get('pegawai', [EmployeeController::class, 'index'])->name('hr.employees.index');
    Route::get('pegawai/tambah', [EmployeeController::class, 'create'])->name('hr.employees.create');
    Route::post('pegawai', [EmployeeController::class, 'store'])->name('hr.employees.store');
    // Impor pegawai dari Excel/CSV (HR-1e, PRD-001 §6.2). Hanya Admin SDM / Admin Yayasan.
    Route::get('pegawai/impor', [EmployeeImportController::class, 'index'])->name('hr.employees.import');
    Route::get('pegawai/impor/template', [EmployeeImportController::class, 'template'])->name('hr.employees.import.template');
    Route::post('pegawai/impor', [EmployeeImportController::class, 'upload'])->name('hr.employees.import.upload');
    Route::post('pegawai/impor/simpan', [EmployeeImportController::class, 'save'])->name('hr.employees.import.save');
    Route::post('pegawai/impor/batal', [EmployeeImportController::class, 'cancel'])->name('hr.employees.import.cancel');

    Route::get('pegawai/{employee}', [EmployeeController::class, 'show'])->whereUuid('employee')->name('hr.employees.show');

    // Aksi di detail pegawai (HR-1d). Hanya Admin SDM / Admin Yayasan.
    Route::patch('pegawai/{employee}', [EmployeeActionController::class, 'updateProfile'])->whereUuid('employee')->name('hr.employees.update');
    Route::post('pegawai/{employee}/status', [EmployeeActionController::class, 'changeStatus'])->whereUuid('employee')->name('hr.employees.status');
    Route::post('pegawai/{employee}/penempatan', [EmployeeActionController::class, 'addPlacement'])->whereUuid('employee')->name('hr.employees.placements.store');
    Route::post('pegawai/{employee}/penempatan/{placement}/akhiri', [EmployeeActionController::class, 'endPlacement'])->whereUuid(['employee', 'placement'])->name('hr.employees.placements.end');
    Route::post('pegawai/{employee}/penempatan/{placement}/mutasi', [EmployeeActionController::class, 'transfer'])->whereUuid(['employee', 'placement'])->name('hr.employees.placements.transfer');
    Route::post('pegawai/{employee}/penempatan/{placement}/utama', [EmployeeActionController::class, 'makePrimary'])->whereUuid(['employee', 'placement'])->name('hr.employees.placements.primary');
    Route::post('pegawai/{employee}/berhenti', [EmployeeActionController::class, 'end'])->whereUuid('employee')->name('hr.employees.end');
    Route::post('pegawai/{employee}/aktifkan', [EmployeeActionController::class, 'rehire'])->whereUuid('employee')->name('hr.employees.rehire');
});
