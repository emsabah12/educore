<?php

use Illuminate\Support\Facades\Route;
use Modules\Core\Http\Controllers\AuditLogController;
use Modules\Core\Http\Controllers\LoginAccountController;
use Modules\Core\Http\Controllers\OrganizationController;
use Modules\Core\Http\Controllers\PasswordChangeController;
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

    // Wajib ganti kata sandi sementara (PRD-000 §4.7 F5a); dijaga middleware EnsureAccountUsable.
    Route::get('akun/ganti-sandi', [PasswordChangeController::class, 'edit'])->name('password.force.edit');
    Route::put('akun/ganti-sandi', [PasswordChangeController::class, 'update'])
        ->middleware('throttle:6,1')
        ->name('password.force.update');
});

// Halaman kerja di dalam yayasan: pohon lembaga (PRD-000 §4.6) dan log audit (OD-17).
Route::middleware(['auth', 'work.context'])->group(function () {
    Route::get('lembaga', [OrganizationController::class, 'index'])->name('organizations.index');
    Route::post('lembaga', [OrganizationController::class, 'store'])->name('organizations.store');
    Route::patch('lembaga/{organization}', [OrganizationController::class, 'update'])->name('organizations.update');
    Route::patch('lembaga/{organization}/induk', [OrganizationController::class, 'move'])->name('organizations.move');
    Route::post('lembaga/{organization}/nonaktifkan', [OrganizationController::class, 'deactivate'])->name('organizations.deactivate');
    Route::post('lembaga/{organization}/aktifkan', [OrganizationController::class, 'activate'])->name('organizations.activate');

    // Log audit baca-saja (OD-17).
    Route::get('audit', [AuditLogController::class, 'index'])->name('audit.index');

    // Kelola akun login anggota, dari detail pegawai (PRD-000 §4.7 F5a). Hanya Admin Yayasan.
    Route::post('akses/anggota/{membership}/akun', [LoginAccountController::class, 'store'])
        ->whereUuid('membership')->name('access.accounts.store');
    Route::post('akses/anggota/{membership}/akun/atur-ulang-sandi', [LoginAccountController::class, 'resetPassword'])
        ->whereUuid('membership')->name('access.accounts.reset');
    Route::post('akses/anggota/{membership}/akun/nonaktifkan', [LoginAccountController::class, 'deactivate'])
        ->whereUuid('membership')->name('access.accounts.deactivate');
    Route::post('akses/anggota/{membership}/akun/aktifkan', [LoginAccountController::class, 'activate'])
        ->whereUuid('membership')->name('access.accounts.activate');
});
