<?php

declare(strict_types=1);

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Modules\Auth\Http\Controllers\Api\v1\AuthController;
use Modules\Auth\Http\Middleware\InjectTenantContext;

Route::prefix('v1/auth')->group(function () {
    // Route Discovery (Public)
    Route::get('tenant-discovery', [AuthController::class, 'discoverTenant']);

    // Route Login (Public)
    Route::post('login-token', [AuthController::class, 'loginToken']);
});

Route::post('/v1/auth/login-token', [AuthController::class, 'loginToken']);
Route::post('/v1/auth/login-session', [AuthController::class, 'loginSession']);

// Protected routes: authentication context must be resolved first.
Route::middleware([InjectTenantContext::class])->group(function () {
    Route::post('/v1/auth/logout', [AuthController::class, 'logout']);
});

Route::middleware([InjectTenantContext::class])->group(function () {
    Route::get('/v1/auth/me', function (Request $request) {
        return response()->json([
            'status' => 'success',
            'current_tenant' => $request->attributes->get(
                'authenticated_tenant_id'
            ),
        ]);
    });
});
