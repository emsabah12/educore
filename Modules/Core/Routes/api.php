<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Modules\Auth\Http\Middleware\InjectTenantContext;
use Modules\Auth\Http\Middleware\RequireGlobalSuperadmin;
use Modules\Auth\Http\Middleware\InjectAuthenticatedUser;
use Modules\Core\Http\Controllers\Api\v1\HealthCheckController;
use Modules\Core\Platform\Http\Controllers\Api\v1\NotificationController;
use Modules\Core\Tenancy\Http\Api\v1\TenantManagementController;

/*
|--------------------------------------------------------------------------
| Core Module API Routes
|--------------------------------------------------------------------------
|
| Core hanya memiliki route platform/infrastructure.
| Domain-specific routes berada di module pemiliknya:
|
| - Academic -> Modules/Academic/Routes/api.php
| - HR       -> Modules/HR/Routes/api.php
| - Auth     -> Modules/Auth/Routes/api.php
|
*/

/*
|--------------------------------------------------------------------------
| Public Platform Health
|--------------------------------------------------------------------------
|
| Health check tidak membutuhkan authentication context.
|
*/

Route::get(
    '/v1/core/health',
    HealthCheckController::class
)->name('api.core.health');

/*
|--------------------------------------------------------------------------
| Tenant-Scoped Notification Platform
|--------------------------------------------------------------------------
|
| Notification dispatch membutuhkan authenticated tenant context.
|
| InjectTenantContext bertanggung jawab untuk:
|
| - memvalidasi Bearer Token;
| - mengekstrak user_id;
| - mengekstrak tenant_id;
| - menginjeksikan authenticated_user_id;
| - menginjeksikan authenticated_tenant_id.
|
| Controller tidak boleh dapat diakses tanpa middleware ini.
|
*/

Route::middleware([
    InjectTenantContext::class,
])->group(function (): void {
    Route::post(
        '/v1/core/notifications/dispatch',
        [NotificationController::class, 'send']
    )->name('api.v1.core.notifications.dispatch');
});

/*
|--------------------------------------------------------------------------
| Global Superadmin Tenant Management
|--------------------------------------------------------------------------
|
| Tenant management menggunakan dua global security boundary:
|
| 1. InjectAuthenticatedUser
|    Memastikan bearer token menghasilkan canonical active user.
|
| 2. RequireGlobalSuperadmin
|    Memastikan authenticated user memiliki privilege global melalui
|    users.is_superadmin.
|
| Operasi ini tidak membutuhkan tenant context, membership context,
| ataupun tenant role karena tenant management berada pada global scope.
|
*/

Route::middleware([
    InjectAuthenticatedUser::class,
    RequireGlobalSuperadmin::class,
])->group(function (): void {
    Route::get(
        '/v1/core/tenants',
        [TenantManagementController::class, 'index'],
    )->name('api.v1.core.tenants.index');

    Route::post(
        '/v1/core/tenants',
        [TenantManagementController::class, 'store'],
    )->name('api.v1.core.tenants.store');

    Route::put(
        '/v1/core/tenants/{id}',
        [TenantManagementController::class, 'update'],
    )->name('api.v1.core.tenants.update');
});
