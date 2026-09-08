<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Modules\Core\Authorization\Http\Web\PlatformRoleController;
use Modules\Core\Governance\Audit\Http\Web\PlatformAuditLogController;
use Modules\Core\Subscription\Http\Web\PlatformAddonController;
use Modules\Core\Subscription\Http\Web\PlatformPlanController;
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

        Route::get(
            '/roles',
            [PlatformRoleController::class, 'index'],
        )->name('roles.index');

        Route::get(
            '/roles/create',
            [PlatformRoleController::class, 'create'],
        )->name('roles.create');

        Route::post(
            '/roles',
            [PlatformRoleController::class, 'store'],
        )->name('roles.store');

        Route::get(
            '/roles/{role}',
            [PlatformRoleController::class, 'show'],
        )->name('roles.show');

        Route::put(
            '/roles/{role}',
            [PlatformRoleController::class, 'update'],
        )->name('roles.update');

        Route::get(
            '/plans',
            [PlatformPlanController::class, 'index'],
        )->name('plans.index');

        Route::get(
            '/plans/create',
            [PlatformPlanController::class, 'create'],
        )->name('plans.create');

        Route::post(
            '/plans',
            [PlatformPlanController::class, 'store'],
        )->name('plans.store');

        Route::get(
            '/plans/{plan}',
            [PlatformPlanController::class, 'show'],
        )->name('plans.show');

        Route::put(
            '/plans/{plan}',
            [PlatformPlanController::class, 'update'],
        )->name('plans.update');

        Route::get(
            '/addons',
            [PlatformAddonController::class, 'index'],
        )->name('addons.index');

        Route::get(
            '/addons/create',
            [PlatformAddonController::class, 'create'],
        )->name('addons.create');

        Route::post(
            '/addons',
            [PlatformAddonController::class, 'store'],
        )->name('addons.store');

        Route::get(
            '/addons/{addon}',
            [PlatformAddonController::class, 'show'],
        )->name('addons.show');

        Route::put(
            '/addons/{addon}',
            [PlatformAddonController::class, 'update'],
        )->name('addons.update');
    });
});
