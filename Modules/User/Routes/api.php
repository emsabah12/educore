<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Modules\Auth\Http\Middleware\InjectAuthenticatedUser;
use Modules\Auth\Http\Middleware\InjectTenantContext;
use Modules\User\Http\Controllers\Api\v1\AssignMembershipRoleController;
use Modules\User\Http\Controllers\Api\v1\MembershipController;
use Modules\User\Http\Controllers\Api\v1\SwitchMembershipController;

Route::middleware([
    InjectTenantContext::class,
    'tenant.role:admin',
])->group(function (): void {
    Route::post(
        '/v1/user/memberships/{target_membership_id}/assign-role',
        AssignMembershipRoleController::class,
    )->name('api.v1.user.rbac.assign');
});

Route::middleware([
    InjectAuthenticatedUser::class,
])->group(function (): void {
    Route::get(
        '/v1/user/my-memberships',
        [
            MembershipController::class,
            'index',
        ],
    )->name('api.v1.user.memberships.index');

    Route::post(
        '/v1/user/memberships/{membership_id}/switch',
        SwitchMembershipController::class,
    )->name('api.v1.user.memberships.switch');
});
