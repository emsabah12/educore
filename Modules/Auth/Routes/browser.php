<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Modules\Auth\Http\Controllers\Browser\v1\BrowserLoginController;
use Modules\Auth\Http\Controllers\Browser\v1\BrowserLogoutController;
use Modules\Auth\Http\Controllers\Browser\v1\BrowserSessionCsrfController;
use Modules\Auth\Http\Controllers\Browser\v1\TenantSelfRegistrationController;

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

    /*
     * §Pendaftaran tenant mandiri (self-service) -- SENGAJA PUBLIK,
     * tanpa middleware autentikasi apa pun. Dibatasi throttle:5,1
     * (5 percobaan/menit per IP) supaya tidak jadi jalur spam
     * pembuatan tenant otomatis, sambil tetap wajar untuk pengguna
     * asli yang mungkin salah isi form beberapa kali.
     */
    Route::post(
        '/auth/register',
        TenantSelfRegistrationController::class,
    )
        ->middleware('throttle:5,1')
        ->name('api.v1.browser.auth.register');

    Route::post(
        '/auth/logout',
        BrowserLogoutController::class,
    )
        ->block(10, 10)
        ->name('api.v1.browser.auth.logout');
});
