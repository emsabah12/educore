<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Modules\Auth\Http\Controllers\Api\v1\AuthenticatedContextController;
use Modules\Auth\Http\Controllers\Browser\v1\BrowserLoginController;
use Modules\Auth\Http\Controllers\Browser\v1\BrowserLogoutController;
use Modules\Auth\Http\Controllers\Browser\v1\BrowserSessionCsrfController;
use Modules\Auth\Http\Middleware\InjectBrowserTenantContext;

Route::prefix('v1/browser')->group(function (): void {
    /*
     * Safe bootstrap endpoint.
     *
     * The surrounding "web" middleware group starts the server-side session
     * and Laravel's request-forgery middleware emits the XSRF-TOKEN cookie.
     */
    Route::get(
        '/session/csrf',
        BrowserSessionCsrfController::class,
    )->name('api.v1.browser.session.csrf');

    Route::post(
        '/auth/login',
        BrowserLoginController::class,
    )->name('api.v1.browser.auth.login');

    Route::get(
        '/auth/me',
        AuthenticatedContextController::class,
    )->middleware([
        InjectBrowserTenantContext::class,
    ])->name('api.v1.browser.auth.me');

    Route::post(
        '/auth/logout',
        BrowserLogoutController::class,
    )
        ->block(10, 10)
        ->name('api.v1.browser.auth.logout');
});
