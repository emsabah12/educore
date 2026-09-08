import {
    defineProtectedRoutePolicy,
} from '@/platform/routing';

/*
 * Canonical HR Workforce route policy.
 *
 * This is static access metadata, not current user authority.
 * CapabilityRuntime remains the owner of runtime permission
 * projection and ProtectedRouteBoundary remains the owner of
 * route-access evaluation.
 *
 * contextRequirement: 'organizational' — HR-013 §6 Target
 * Employee Scope Rule requires an organizational workspace to
 * already be selected; this route never renders under a
 * TENANT-scoped workspace.
 */
export const hrWorkforceRoutePolicy =
    defineProtectedRoutePolicy({
        routeId:
            'hr.workforce.index',

        contextRequirement:
            'organizational',

        authorizationScope:
            'workspace',

        requiredPermissions: {
            mode:
                'single',

            permission:
                'hr.employees.view',
        },
    });

/*
 * Public route contribution owned by the HR module.
 *
 * The application composes this structural contract without
 * the module depending on app/router.tsx.
 */
export const hrRouteContributions = [
    {
        routeId:
            'hr.workforce.index',

        /*
         * Nested beneath the authenticated application shell,
         * therefore this is intentionally relative.
         */
        path:
            'hr/workforce',

        accessPolicy:
            hrWorkforceRoutePolicy,

        /*
         * Keep the page implementation outside the initial
         * application bundle. Static route and policy metadata
         * remain available during router composition.
         */
        lazy:
            async () => {
                const {
                    HrWorkforcePage,
                } =
                    await import(
                        '@/modules/hr/workforce/HrWorkforcePage'
                    );

                return {
                    Component:
                        HrWorkforcePage,
                };
            },
    },
] as const;