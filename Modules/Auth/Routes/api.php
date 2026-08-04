<?php

declare(strict_types=1);

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Modules\Auth\Http\Controllers\Api\v1\AuthController;
use Modules\Auth\Http\Middleware\InjectTenantContext;

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
            static function (Request $request) {
                return response()->json([
                    'status' => 'success',
                    'current_tenant' => $request->attributes->get(
                        'authenticated_tenant_id',
                    ),
                ]);
            },
        )->name('api.v1.auth.me');
    });
});
