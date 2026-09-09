import {
    defineProtectedRoutePolicy,
} from '@/platform/routing';

/*
 * Canonical Tenant Custom Roles route policy.
 *
 * contextRequirement: 'tenant' — the custom_roles Subscription
 * feature (Step D) is a TENANT-level entitlement, not an
 * organizational one, so this route never requires an
 * organizational Workspace to be selected first.
 */
export const settingsTenantRolesRoutePolicy =
    defineProtectedRoutePolicy({
        routeId:
            'settings.tenant-roles.index',

        contextRequirement:
            'tenant',

        authorizationScope:
            'tenant',

        requiredPermissions: {
            mode:
                'single',

            permission:
                'tenant.custom-roles.manage',
        },
    });

export const settingsRouteContributions = [
    {
        routeId:
            'settings.tenant-roles.index',

        path:
            'settings/roles',

        accessPolicy:
            settingsTenantRolesRoutePolicy,

        lazy:
            async () => {
                const {
                    TenantRolesPage,
                } =
                    await import(
                        '@/modules/settings/roles/TenantRolesPage'
                    );

                return {
                    Component:
                        TenantRolesPage,
                };
            },
    },
] as const;