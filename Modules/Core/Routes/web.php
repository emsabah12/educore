<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Modules\Core\Governance\Audit\Http\Web\PlatformAuditLogController;
use Modules\Core\Tenancy\Http\Web\PlatformAuthController;
use Modules\Core\Tenancy\Http\Web\PlatformDashboardController;
use Modules\Core\Tenancy\Http\Web\PlatformTenantController;

Route::prefix('platform')->name('platform.')->group(function (): void {
    Route::get(
        '/login',
        [PlatformAuthController::class, 'showLoginForm'],
    )->name('login');

    Route::post(
        '/login',
        [PlatformAuthController::class, 'login'],
    )->name('login.attempt');

    Route::post(
        '/logout',
        [PlatformAuthController::class, 'logout'],
    )->name('logout');

    Route::middleware(['platform.superadmin'])->group(function (): void {
        Route::get(
            '/dashboard',
            [PlatformDashboardController::class, 'index'],
        )->name('dashboard');

        Route::get(
            '/tenants',
            [PlatformTenantController::class, 'index'],
        )->name('tenants.index');

        Route::get(
            '/tenants/create',
            [PlatformTenantController::class, 'create'],
        )->name('tenants.create');

        Route::post(
            '/tenants',
            [PlatformTenantController::class, 'store'],
        )->name('tenants.store');

        Route::get(
            '/tenants/{tenant}',
            [PlatformTenantController::class, 'show'],
        )->name('tenants.show');

        Route::post(
            '/tenants/{tenant}/toggle-status',
            [PlatformTenantController::class, 'toggleStatus'],
        )->name('tenants.toggle-status');

        Route::get(
            '/audit-logs',
            [PlatformAuditLogController::class, 'index'],
        )->name('audit-logs.index');
    });
});
