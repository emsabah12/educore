<?php

use Illuminate\Support\Facades\Route;
use Modules\Core\Http\Controllers\OrganizationController;
use Modules\Core\Http\Controllers\PlatformController;
use Modules\Core\Http\Controllers\WorkContextController;

/*
 * Route web modul Core. Dimuat oleh ModuleServiceProvider dengan middleware group "web".
 *
 * Kelompok pertama sengaja TIDAK memakai `work.context`, karena justru dipakai untuk
 * membentuk konteks kerja. Halaman kerja di dalam yayasan WAJIB memakai `work.context`.
 */
Route::middleware(['auth'])->group(function () {
    Route::get('konteks/yayasan', [WorkContextController::class, 'editTenant'])->name('context.tenant.edit');
    Route::post('konteks/yayasan', [WorkContextController::class, 'updateTenant'])->name('context.tenant.update');

    Route::get('konteks/lembaga', [WorkContextController::class, 'editWorkspace'])->name('context.workspace.edit');
    Route::post('konteks/lembaga', [WorkContextController::class, 'updateWorkspace'])->name('context.workspace.update');

    Route::get('belum-terdaftar', [WorkContextController::class, 'unregistered'])->name('context.unregistered');

    Route::get('platform', [PlatformController::class, 'index'])->name('platform.home');
});

// Halaman admin pohon lembaga (PRD-000 §4.6, OD-14).
Route::middleware(['auth', 'work.context'])->group(function () {
    Route::get('lembaga', [OrganizationController::class, 'index'])->name('organizations.index');
    Route::post('lembaga', [OrganizationController::class, 'store'])->name('organizations.store');
    Route::patch('lembaga/{organization}', [OrganizationController::class, 'update'])->name('organizations.update');
    Route::patch('lembaga/{organization}/induk', [OrganizationController::class, 'move'])->name('organizations.move');
    Route::post('lembaga/{organization}/nonaktifkan', [OrganizationController::class, 'deactivate'])->name('organizations.deactivate');
    Route::post('lembaga/{organization}/aktifkan', [OrganizationController::class, 'activate'])->name('organizations.activate');
});
