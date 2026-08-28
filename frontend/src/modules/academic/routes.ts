import {
    defineProtectedRoutePolicy,
} from '@/platform/routing';

/*
 * Canonical Academic Students route policy.
 *
 * This is static access metadata, not current user authority.
 * CapabilityRuntime remains the owner of runtime permission
 * projection and ProtectedRouteBoundary remains the owner of
 * route-access evaluation.
 */
export const academicStudentsRoutePolicy =
    defineProtectedRoutePolicy({
        routeId:
            'academic.students.index',

        contextRequirement:
            'tenant',

        authorizationScope:
            'tenant',

        requiredPermissions: {
            mode:
                'single',

            permission:
                'academic.students.view',
        },
    });

/*
 * Public route contribution owned by the Academic module.
 *
 * The application composes this structural contract without
 * the module depending on app/router.tsx.
 */
export const academicRouteContributions = [
    {
        routeId:
            'academic.students.index',

        /*
         * Nested beneath the authenticated application shell,
         * therefore this is intentionally relative.
         */
        path:
            'academic/students',

        accessPolicy:
            academicStudentsRoutePolicy,

        /*
         * Keep the page implementation outside the initial
         * application bundle. Static route and policy metadata
         * remain available during router composition.
         */
        lazy:
            async () => {
                const {
                    AcademicStudentsPage,
                } =
                    await import(
                        '@/modules/academic/students/AcademicStudentsPage'
                    );

                return {
                    Component:
                        AcademicStudentsPage,
                };
            },
    },
] as const;
