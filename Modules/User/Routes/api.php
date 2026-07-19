<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Modules\User\Http\Controllers\Api\v1\ContextualRbacController;
use Modules\User\Http\Controllers\Api\v1\MembershipResolutionController;

/*
|--------------------------------------------------------------------------
| User Module API Routes
|--------------------------------------------------------------------------
*/

Route::middleware(['auth:sanctum', 'tenant.role:admin'])->group(function () {
    // Jalur eksekusi mutasi hak akses mikro
    Route::post('/v1/user/memberships/{membership_id}/assign-role', [ContextualRbacController::class, 'assignRoleToMembership'])
        ->name('api.v1.user.rbac.assign');
});

Route::middleware(['auth:sanctum'])->group(function () {
    // Mendapatkan seluruh daftar sekolah saya
    Route::get('/v1/user/my-memberships', [MembershipResolutionController::class, 'index'])
        ->name('api.v1.user.memberships.index');

    // Beralih konteks sekolah aktif
    Route::post('/v1/user/memberships/{membership_id}/switch', [MembershipResolutionController::class, 'switchContext'])
        ->name('api.v1.user.memberships.switch');
});
