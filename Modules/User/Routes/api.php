<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Modules\Auth\Http\Middleware\InjectAuthenticatedUser;
use Modules\Auth\Http\Middleware\InjectTenantContext;
use Modules\Auth\Http\Middleware\InjectTransportAwareAuthenticatedUser;
use Modules\Auth\Http\Middleware\UseBrowserSessionForCanonicalApi;
use Modules\User\Http\Controllers\Api\v1\AssignMembershipRoleController;
use Modules\User\Http\Controllers\Api\v1\MembershipController;
use Modules\User\Http\Controllers\Api\v1\SwitchMembershipController;
use Modules\User\Http\Controllers\Api\v1\WorkspaceController;

Route::middleware([
    InjectTenantContext::class,
    'tenant.role:admin',
])->group(function (): void {
    Route::post(
        '/v1/user/memberships/{target_membership_id}/assign-role',
        AssignMembershipRoleController::class,
    )->name('api.v1.user.rbac.assign');
});

/*
|--------------------------------------------------------------------------
| Current Membership / Tenant Context
|--------------------------------------------------------------------------
|
| Workspace discovery membutuhkan verified Membership + Tenant,
| sehingga tidak cukup hanya memakai InjectAuthenticatedUser.
|
*/

Route::middleware([
    InjectTenantContext::class,
])->group(function (): void {
    Route::get(
        '/v1/user/my-workspaces',
        [
            WorkspaceController::class,
            'index',
        ],
    )->name('api.v1.user.workspaces.index');
});

/*
|--------------------------------------------------------------------------
| Global User Membership Operations
|--------------------------------------------------------------------------
|
| Listing/switching Membership tidak boleh terikat current Tenant,
| karena tujuan endpoint ini justru memungkinkan User memilih Tenant lain.
|
*/

Route::get(
    '/v1/user/my-memberships',
    [
        MembershipController::class,
        'index',
    ],
)->middleware([
    UseBrowserSessionForCanonicalApi::class,
    InjectTransportAwareAuthenticatedUser::class,
])->name('api.v1.user.memberships.index');

/*
 * Canonical Membership switch remains bearer-only because BrowserSession
 * switching has its own credential-custody endpoint and must never return the
 * newly issued bearer token to the browser.
 */
Route::post(
    '/v1/user/memberships/{membership_id}/switch',
    SwitchMembershipController::class,
)->middleware([
    InjectAuthenticatedUser::class,
])->name('api.v1.user.memberships.switch');
