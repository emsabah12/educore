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

/*
 * Canonical Kelola Organisasi route policy.
 *
 * contextRequirement: 'tenant' — Organisasi itu sendiri adalah
 * prasyarat untuk modul organizational-scoped lain (HR, dst).
 * Endpoint pembuatannya SENGAJA tidak boleh mensyaratkan
 * Workspace organisasi sudah dipilih terlebih dahulu (lingkaran
 * setan) — lihat OrganizationManagementController di backend.
 *
 * organization.manage adalah permission RBAC biasa, BUKAN
 * Subscription feature — tidak ada requiredFeature terkait di
 * navigation-definition.ts untuk destinasi ini.
 */
export const settingsOrganizationsRoutePolicy =
    defineProtectedRoutePolicy({
        routeId:
            'settings.organizations.index',

        contextRequirement:
            'tenant',

        authorizationScope:
            'tenant',

        requiredPermissions: {
            mode:
                'single',

            permission:
                'organization.manage',
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

    {
        routeId:
            'settings.organizations.index',

        path:
            'settings/organizations',

        accessPolicy:
            settingsOrganizationsRoutePolicy,

        lazy:
            async () => {
                const {
                    OrganizationsPage,
                } =
                    await import(
                        '@/modules/settings/organizations/OrganizationsPage'
                    );

                return {
                    Component:
                        OrganizationsPage,
                };
            },
    },
] as const;