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

/*
 * Kelola Unit di bawah satu Organization — SUB-HALAMAN dari
 * Kelola Organisasi (dijangkau via tombol "Kelola Unit" di baris
 * tabel OrganizationsPage), BUKAN entri menu navigasi top-level
 * tersendiri. Sama seperti hr.workforce.show (detail pegawai),
 * rute tanpa entri navigasi tidak perlu didaftarkan silang ke
 * application-route-access.ts — hanya rute YANG MUNCUL di
 * navigation-definition.ts butuh itu, karena registry di sana
 * dipakai khusus oleh proyeksi visibilitas navigasi, bukan oleh
 * guard rute itu sendiri (accessPolicy di bawah ini dikonsumsi
 * router.tsx secara langsung).
 *
 * Permission reuse organization.units.manage — sama persis dengan
 * yang menggate endpoint backend-nya, TIDAK ada permission
 * terpisah untuk "melihat halaman ini" vs "mengelola isinya".
 */
export const settingsOrganizationUnitsRoutePolicy =
    defineProtectedRoutePolicy({
        routeId:
            'settings.organizations.units.index',

        contextRequirement:
            'tenant',

        authorizationScope:
            'tenant',

        requiredPermissions: {
            mode:
                'single',

            permission:
                'organization.units.manage',
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

    {
        routeId:
            'settings.organizations.units.index',

        path:
            'settings/organizations/:organizationId/units',

        accessPolicy:
            settingsOrganizationUnitsRoutePolicy,

        lazy:
            async () => {
                const {
                    OrganizationUnitsPage,
                } =
                    await import(
                        '@/modules/settings/organizations/OrganizationUnitsPage'
                    );

                return {
                    Component:
                        OrganizationUnitsPage,
                };
            },
    },
] as const;