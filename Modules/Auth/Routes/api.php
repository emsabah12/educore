<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Modules\Auth\Http\Controllers\Api\v1\AuthController;
use Modules\Auth\Http\Controllers\Api\v1\AuthenticatedContextController;
use Modules\Auth\Http\Controllers\Api\v1\AuthIdentityController;
use Modules\Auth\Http\Middleware\InjectAuthenticatedUser;
use Modules\Auth\Http\Middleware\InjectTenantContext;
use Modules\Auth\Http\Middleware\InjectTransportAwareAuthenticatedUser;
use Modules\Auth\Http\Middleware\InjectTransportAwareTenantContext;
use Modules\Auth\Http\Middleware\UseBrowserSessionForCanonicalApi;
use Modules\Core\Authorization\Http\Api\v1\RoleCatalogController;
use Modules\Core\Authorization\Http\Api\v1\TenantCapabilityController;
use Modules\Core\Authorization\Http\Api\v1\WorkspaceCapabilityController;
use Modules\Core\Authorization\Http\Middleware\RequireGlobalSuperadmin;
use Modules\Core\Organization\Http\Api\v1\OrganizationalAssignmentManagementController;
use Modules\Core\Organization\Http\Api\v1\OrganizationManagementController;
use Modules\Core\Organization\Http\Api\v1\OrganizationUnitManagementController;
use Modules\Core\Organization\Http\Middleware\InjectOrganizationalContext;
use Modules\Core\Platform\Http\Controllers\Api\v1\NotificationController;
use Modules\Core\Subscription\Http\Api\v1\TenantEffectiveFeaturesController;
use Modules\Core\Subscription\Http\Api\v1\TenantRoleController;
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
     * Canonical global identity introspection supports both authenticated
     * bearer clients and the hardened BrowserSession transport.
     *
     * This boundary is intentionally User/Person-only and must never create
     * Membership or Tenant context.
     */
    Route::get(
        '/identity',
        AuthIdentityController::class,
    )->middleware([
        UseBrowserSessionForCanonicalApi::class,
        InjectTransportAwareAuthenticatedUser::class,
    ])->name('api.v1.auth.identity');

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

/*
 * §PRD Subscription & Custom Role — role KUSTOM milik tenant sendiri
 * (Step E). Otorisasi diperiksa lewat PERMISSION
 * `tenant.custom-roles.manage` (bukan nama role di-hardcode) — sesuai
 * keputusan "owner, admin, tim ops adalah PERAN, bukan nama role
 * tetap" dari Step D. Dual transport SEJAK AWAL (bukan ditambal
 * belakangan seperti HR-004) karena endpoint ini memang dipanggil
 * langsung oleh frontend tenant berbasis sesi browser.
 */
Route::middleware([
    UseBrowserSessionForCanonicalApi::class,
    InjectTransportAwareTenantContext::class,
    'tenant.permission:tenant.custom-roles.manage',
])->prefix('v1/core/tenant-roles')->group(function (): void {
    Route::get(
        '/',
        [TenantRoleController::class, 'index'],
    )->name('api.v1.core.tenant-roles.index');

    Route::post(
        '/',
        [TenantRoleController::class, 'store'],
    )->name('api.v1.core.tenant-roles.store');

    Route::get(
        '/assignable-permissions',
        [TenantRoleController::class, 'assignablePermissions'],
    )->name('api.v1.core.tenant-roles.assignable-permissions');

    Route::get(
        '/{roleId}',
        [TenantRoleController::class, 'show'],
    )->name('api.v1.core.tenant-roles.show');

    Route::put(
        '/{roleId}',
        [TenantRoleController::class, 'update'],
    )->name('api.v1.core.tenant-roles.update');
});

/*
 * Read-only Subscription feature projection for the current tenant —
 * ANY authenticated tenant member may read this (no
 * tenant.permission gate), since it powers frontend navigation
 * visibility rather than protecting a sensitive management action.
 * Dual transport for the same browser-session frontend reason as
 * /core/tenant-roles above.
 */
Route::middleware([
    UseBrowserSessionForCanonicalApi::class,
    InjectTransportAwareTenantContext::class,
])->prefix('v1/core/tenant-subscription')->group(function (): void {
    Route::get(
        '/effective-features',
        [TenantEffectiveFeaturesController::class, 'index'],
    )->name('api.v1.core.tenant-subscription.effective-features');
});

/*
 * Kelola Organisasi milik tenant — level TENANT, bukan
 * organizational-scoped (lihat catatan arsitektur di
 * OrganizationManagementController). Dual transport untuk frontend
 * browser-session, pola sama seperti /core/tenant-roles.
 */
Route::middleware([
    UseBrowserSessionForCanonicalApi::class,
    InjectTransportAwareTenantContext::class,
    'tenant.permission:organization.manage',
])->prefix('v1/core/organizations')->group(function (): void {
    Route::get(
        '/',
        [OrganizationManagementController::class, 'index'],
    )->name('api.v1.core.organizations.index');

    Route::post(
        '/',
        [OrganizationManagementController::class, 'store'],
    )->name('api.v1.core.organizations.store');
});

/*
 * Kelola Unit di bawah SATU Organization — selalu nested, tidak
 * pernah koleksi Unit lintas-Organization (lihat catatan arsitektur
 * di OrganizationUnitManagementController). Permission terpisah dari
 * organization.manage supaya bisa didelegasikan secara granular ke
 * depan.
 */
Route::middleware([
    UseBrowserSessionForCanonicalApi::class,
    InjectTransportAwareTenantContext::class,
    'tenant.permission:organization.units.manage',
])->prefix('v1/core/organizations/{organization}/units')->group(function (): void {
    Route::get(
        '/',
        [OrganizationUnitManagementController::class, 'index'],
    )->name('api.v1.core.organizations.units.index');

    Route::post(
        '/',
        [OrganizationUnitManagementController::class, 'store'],
    )->name('api.v1.core.organizations.units.store');
});

/*
 * "Assign member" management surface — lihat catatan arsitektur di
 * OrganizationalAssignmentManagementController. Permission terpisah
 * dari organization.units.manage: menempatkan orang ke suatu tempat
 * adalah tindakan berbeda dari sekadar mendefinisikan strukturnya.
 */
Route::middleware([
    UseBrowserSessionForCanonicalApi::class,
    InjectTransportAwareTenantContext::class,
    'tenant.permission:organization.assignments.manage',
])->prefix('v1/core/organizations/{organization}/assignments')->group(function (): void {
    Route::get(
        '/',
        [OrganizationalAssignmentManagementController::class, 'index'],
    )->name('api.v1.core.organizations.assignments.index');

    Route::get(
        '/candidate-memberships',
        [OrganizationalAssignmentManagementController::class, 'candidateMemberships'],
    )->name('api.v1.core.organizations.assignments.candidate-memberships');

    Route::post(
        '/',
        [OrganizationalAssignmentManagementController::class, 'store'],
    )->name('api.v1.core.organizations.assignments.store');

    Route::post(
        '/{assignment}/deactivate',
        [OrganizationalAssignmentManagementController::class, 'deactivate'],
    )->name('api.v1.core.organizations.assignments.deactivate');
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

    Route::post(
        '/v1/core/tenants/with-new-admin',
        [
            TenantManagementController::class,
            'storeWithNewAdmin',
        ],
    )->name('api.v1.core.tenants.store-with-new-admin');

    Route::put(
        '/v1/core/tenants/{id}',
        [
            TenantManagementController::class,
            'update',
        ],
    )->name('api.v1.core.tenants.update');
});
