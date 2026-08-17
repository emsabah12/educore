<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Modules\Auth\Http\Controllers\Api\v1\AuthController;
use Modules\Auth\Http\Controllers\Api\v1\AuthenticatedContextController;
use Modules\Auth\Http\Middleware\InjectAuthenticatedUser;
use Modules\Auth\Http\Middleware\InjectTenantContext;
use Modules\Core\Authorization\Http\Api\v1\RoleCatalogController;
use Modules\Core\Authorization\Http\Middleware\RequireGlobalSuperadmin;
use Modules\Core\Platform\Http\Controllers\Api\v1\NotificationController;
use Modules\Core\Tenancy\Http\Api\v1\TenantManagementController;

Route::prefix('v1/auth')->group(function (): void {
    /*
     * Public stateless authentication route.
     */
    Route::post(
        '/login-token',
        [
            AuthController::class,
            'loginToken',
        ],
    )->name('api.v1.auth.login-token');

    /*
     * Tenant-aware authenticated routes.
     */
    Route::middleware([
        InjectTenantContext::class,
    ])->group(function (): void {
        Route::post(
            '/logout',
            [
                AuthController::class,
                'logout',
            ],
        )->name('api.v1.auth.logout');

        Route::get(
            '/me',
            AuthenticatedContextController::class,
        )->name('api.v1.auth.me');
    });
});

/*
|--------------------------------------------------------------------------
| Authenticated Core Capability Composition
|--------------------------------------------------------------------------
|
| Auth owns bearer-token authentication and composes secured entry points
| for Core capabilities. Core therefore remains independent from Auth while
| the public API paths and route names remain unchanged.
|
*/

Route::middleware([
    InjectTenantContext::class,
])->group(function (): void {
    Route::post(
        '/v1/core/notifications/dispatch',
        [NotificationController::class, 'send'],
    )->name('api.v1.core.notifications.dispatch');
});

Route::middleware([
    InjectTenantContext::class,
    'tenant.role:admin',
])->group(function (): void {
    Route::get(
        '/v1/core/authorization/roles',
        '\\' . RoleCatalogController::class,
    )->name('api.v1.core.authorization.roles.index');
});

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
