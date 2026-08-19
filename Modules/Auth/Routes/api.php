<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Modules\Auth\Http\Controllers\Api\v1\AuthController;
use Modules\Auth\Http\Controllers\Api\v1\AuthenticatedContextController;
use Modules\Auth\Http\Middleware\InjectAuthenticatedUser;
use Modules\Auth\Http\Middleware\InjectTenantContext;
use Modules\Auth\Http\Middleware\InjectTransportAwareTenantContext;
use Modules\Auth\Http\Middleware\UseBrowserSessionForCanonicalApi;
use Modules\Core\Authorization\Http\Api\v1\RoleCatalogController;
use Modules\Core\Authorization\Http\Api\v1\TenantCapabilityController;
use Modules\Core\Authorization\Http\Api\v1\WorkspaceCapabilityController;
use Modules\Core\Authorization\Http\Middleware\RequireGlobalSuperadmin;
use Modules\Core\Organization\Http\Middleware\InjectOrganizationalContext;
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
     * Canonical bearer logout remains stateless. Browser logout has its own
     * credential-revocation/session-destruction boundary.
     */
    Route::post(
        '/logout',
        [
            AuthController::class,
            'logout',
        ],
    )->middleware([
        InjectTenantContext::class,
    ])->name('api.v1.auth.logout');

    /*
     * Canonical authenticated context supports two transports on one resource:
     *
     * - BearerAuth remains stateless for API/mobile clients.
     * - BrowserSessionAuth conditionally activates the server-side session and
     *   resolves the tab-local Membership locator to a server-held credential.
     */
    Route::get(
        '/me',
        AuthenticatedContextController::class,
    )->middleware([
        UseBrowserSessionForCanonicalApi::class,
        InjectTransportAwareTenantContext::class,
    ])->name('api.v1.auth.me');
});

/*
|--------------------------------------------------------------------------
| Authenticated Core Capability Composition
|--------------------------------------------------------------------------
|
| Auth owns bearer-token authentication and composes secured entry points
| for Core capabilities.
|
| Core therefore remains independent from Auth while HTTP composition
| remains at the authentication boundary.
|
*/

Route::post(
    '/v1/core/notifications/dispatch',
    [
        NotificationController::class,
        'send',
    ],
)->middleware([
    InjectTenantContext::class,
])->name('api.v1.core.notifications.dispatch');

/*
 * Tenant-level capability projection supports the canonical dual transport.
 * Organizational context is intentionally optional/not resolved here.
 */
Route::get(
    '/v1/core/authorization/capabilities',
    TenantCapabilityController::class,
)->middleware([
    UseBrowserSessionForCanonicalApi::class,
    InjectTransportAwareTenantContext::class,
])->name(
    'api.v1.core.authorization.capabilities.index',
);

/*
 * Workspace capability projection uses the same verified Tenant/Membership
 * transport before resolving the organizational locator. Middleware ordering
 * is security significant for both bearer and BrowserSession clients.
 */
Route::get(
    '/v1/core/authorization/workspace-capabilities',
    WorkspaceCapabilityController::class,
)->middleware([
    UseBrowserSessionForCanonicalApi::class,
    InjectTransportAwareTenantContext::class,
    InjectOrganizationalContext::class,
])->name(
    'api.v1.core.authorization.workspace-capabilities.index',
);

Route::middleware([
    InjectTenantContext::class,
    'tenant.role:admin',
])->group(function (): void {
    Route::get(
        '/v1/core/authorization/roles',
        '\\'.RoleCatalogController::class,
    )->name(
        'api.v1.core.authorization.roles.index',
    );
});

Route::middleware([
    InjectAuthenticatedUser::class,
    RequireGlobalSuperadmin::class,
])->group(function (): void {
    Route::get(
        '/v1/core/tenants',
        [
            TenantManagementController::class,
            'index',
        ],
    )->name('api.v1.core.tenants.index');

    Route::post(
        '/v1/core/tenants',
        [
            TenantManagementController::class,
            'store',
        ],
    )->name('api.v1.core.tenants.store');

    Route::put(
        '/v1/core/tenants/{id}',
        [
            TenantManagementController::class,
            'update',
        ],
    )->name('api.v1.core.tenants.update');
});
