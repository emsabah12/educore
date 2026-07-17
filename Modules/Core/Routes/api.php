<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Modules\Core\Http\Controllers\Api\v1\TenantManagementController;
use Modules\Core\Http\Controllers\Api\v1\PegawaiManagementController;
use Modules\Core\Http\Controllers\Api\v1\AcademicClassController;
use Modules\Core\Http\Controllers\Api\v1\AcademicSubjectController;
use Modules\Core\Http\Controllers\Api\v1\SantriManagementController;
use Modules\Core\Http\Controllers\Api\v1\WalisantriManagementController;
use Modules\Core\Http\Controllers\Api\v1\WalisantriSantriManagementController;
use Modules\Auth\Http\Middleware\InjectTenantContext;
use Modules\Core\Http\Middleware\RequireGlobalSuperadmin;

/*
|--------------------------------------------------------------------------
| Core Module API Routes
|--------------------------------------------------------------------------
*/

Route::middleware([InjectTenantContext::class])->group(function () {
    // Pegawai Scoped Routes
    Route::get('/v1/core/pegawais', [PegawaiManagementController::class, 'index']);
    Route::post('/v1/core/pegawais', [PegawaiManagementController::class, 'store']);

    // Academic Classes Routes
    Route::get('/v1/core/academic/classes', [AcademicClassController::class, 'index']);
    Route::post('/v1/core/academic/classes', [AcademicClassController::class, 'store']);

    // Academic Subjects Routes
    Route::get('/v1/core/academic/subjects', [AcademicSubjectController::class, 'index']);
    Route::post('/v1/core/academic/subjects', [AcademicSubjectController::class, 'store']);


    // Santri/Student Scoped Routes
    Route::get('/v1/core/santris', [SantriManagementController::class, 'index']);
    Route::post('/v1/core/santris', [SantriManagementController::class, 'store']);

    // Walisantri/Guardian Scoped Routes
    Route::get('/v1/core/walisantris', [WalisantriManagementController::class, 'index']);
    Route::post('/v1/core/walisantris', [WalisantriManagementController::class, 'store']);

    // Guardian-Student Pivot Mapping Routes
    Route::get('/v1/core/walisantris/{walisantriId}/santris', [WalisantriSantriManagementController::class, 'index']);
    Route::post('/v1/core/walisantris/associations', [WalisantriSantriManagementController::class, 'store']);
    Route::delete('/v1/core/walisantris/associations', [WalisantriSantriManagementController::class, 'destroy']);
});

// Global Superadmin Scoped Routes
Route::middleware([InjectTenantContext::class, RequireGlobalSuperadmin::class])->group(function () {
    Route::get('/v1/core/tenants', [TenantManagementController::class, 'index']);
    Route::post('/v1/core/tenants', [TenantManagementController::class, 'store']);
    Route::put('/v1/core/tenants/{id}', [TenantManagementController::class, 'update']);
});
