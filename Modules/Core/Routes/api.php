<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Modules\Core\Tenancy\Http\Api\v1\TenantManagementController;
use Modules\Core\Platform\Http\Controllers\Api\v1\NotificationController;
use Modules\Core\Http\Controllers\Api\v1\HealthCheckController;
use Modules\Auth\Http\Middleware\InjectTenantContext;
use Modules\Auth\Http\Middleware\RequireGlobalSuperadmin;

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
*/


Route::get('/v1/core/health', HealthCheckController::class)
    ->name('api.core.health');


/*
|--------------------------------------------------------------------------
| Centralized Notification Platform
|--------------------------------------------------------------------------
|
| Tenant context akan divalidasi oleh middleware
| InjectTenantContext pada route group yang sesuai.
|
*/
Route::post(
    '/v1/core/notifications/dispatch',
    [NotificationController::class, 'send']
)->name('api.v1.core.notifications.dispatch');


/*
|--------------------------------------------------------------------------
| Tenant-Scoped Platform Routes
|--------------------------------------------------------------------------
*/

Route::middleware([InjectTenantContext::class])->group(function (): void {
    Route::post(
        '/v1/core/notifications/dispatch',
        [NotificationController::class, 'send']
    )->name('api.v1.core.notifications.dispatch');
});

/*
|--------------------------------------------------------------------------
| Global Superadmin Tenant Management
|--------------------------------------------------------------------------
*/

Route::middleware([
    InjectTenantContext::class,
    RequireGlobalSuperadmin::class,
])->group(function (): void {
    Route::get(
        '/v1/core/tenants',
        [TenantManagementController::class, 'index']
    )->name('api.v1.core.tenants.index');

    Route::post(
        '/v1/core/tenants',
        [TenantManagementController::class, 'store']
    )->name('api.v1.core.tenants.store');

    Route::put(
        '/v1/core/tenants/{id}',
        [TenantManagementController::class, 'update']
    )->name('api.v1.core.tenants.update');
});
