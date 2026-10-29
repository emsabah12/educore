<?php

use Illuminate\Support\Facades\Route;
use Modules\Finance\Http\Controllers\FeeSettingsController;

/*
 * Route web modul Finance (PRD-003 §6). Dimuat oleh ModuleServiceProvider dengan middleware group "web".
 * Semua halaman Finance bekerja di dalam yayasan, jadi wajib memakai `work.context`.
 */
Route::middleware(['auth', 'work.context'])->group(function () {
    // Pengaturan keuangan (FN-1a): jenis biaya & tarif. Hanya finance.settings.manage tingkat Yayasan.
    Route::get('keuangan/pengaturan', [FeeSettingsController::class, 'index'])->name('finance.settings.index');
    Route::post('keuangan/jenis-biaya', [FeeSettingsController::class, 'store'])->name('finance.fee-types.store');
    Route::patch('keuangan/jenis-biaya/{type}', [FeeSettingsController::class, 'update'])->whereUuid('type')->name('finance.fee-types.update');
    Route::post('keuangan/jenis-biaya/{type}/nonaktifkan', [FeeSettingsController::class, 'deactivate'])->whereUuid('type')->name('finance.fee-types.deactivate');
    Route::post('keuangan/jenis-biaya/{type}/aktifkan', [FeeSettingsController::class, 'activate'])->whereUuid('type')->name('finance.fee-types.activate');
    Route::post('keuangan/jenis-biaya/{type}/tarif', [FeeSettingsController::class, 'setRate'])->whereUuid('type')->name('finance.fee-rates.set');
    Route::delete('keuangan/tarif/{rate}', [FeeSettingsController::class, 'removeRate'])->whereUuid('rate')->name('finance.fee-rates.remove');
});
