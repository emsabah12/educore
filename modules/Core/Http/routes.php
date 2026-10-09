<?php

use Illuminate\Support\Facades\Route;
use Modules\Core\Http\Controllers\PlatformController;
use Modules\Core\Http\Controllers\WorkContextController;

/*
 * Route web modul Core. Dimuat oleh ModuleServiceProvider dengan middleware group "web".
 * Halaman di sini sengaja TIDAK memakai `work.context`, karena justru dipakai untuk
 * membentuk konteks kerja.
 */
Route::middleware(['auth'])->group(function () {
    Route::get('konteks/yayasan', [WorkContextController::class, 'editTenant'])->name('context.tenant.edit');
    Route::post('konteks/yayasan', [WorkContextController::class, 'updateTenant'])->name('context.tenant.update');

    Route::get('konteks/lembaga', [WorkContextController::class, 'editWorkspace'])->name('context.workspace.edit');
    Route::post('konteks/lembaga', [WorkContextController::class, 'updateWorkspace'])->name('context.workspace.update');

    Route::get('belum-terdaftar', [WorkContextController::class, 'unregistered'])->name('context.unregistered');

    Route::get('platform', [PlatformController::class, 'index'])->name('platform.home');
});
