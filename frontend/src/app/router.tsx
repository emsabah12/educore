import {
    createBrowserRouter,
    Outlet,
    type RouteObject,
} from 'react-router';

import {
    App,
} from '@/app/App';
import {
    NotFoundPage,
} from '@/app/NotFoundPage';
import {
    RouteErrorPage,
} from '@/app/RouteErrorPage';
import {
    LoginRouteBoundary,
} from '@/app/routing/LoginRouteBoundary';
import {
    ProtectedApplicationAccessBoundary,
} from '@/app/routing/ProtectedApplicationAccessBoundary';
import {
    ProtectedRouteBoundary,
} from '@/app/routing/ProtectedRouteBoundary';
import {
    ProtectedApplicationLifecycleBoundary,
} from '@/app/routing/ProtectedApplicationLifecycleBoundary';
import {
    AuthenticatedApplicationShell,
} from '@/app/shell/AuthenticatedApplicationShell';
import {
    academicRouteContributions,
} from '@/modules/academic/routes';
import type {
    ProtectedRoutePolicy,
} from '@/platform/routing';

export interface BusinessModuleRouteContribution {
    /*
     * Stable module route identity owned by the module's
     * public contract.
     */
    readonly routeId:
        string;

    /*
     * Static application path contributed by the module.
     *
     * The application composes this path but does not own
     * the module implementation behind it.
     */
    readonly path:
        string;

    /*
     * Canonical static access metadata owned by the
     * contributed business route.
     *
     * Route existence remains independent from the current
     * Capability projection. Runtime authorization is
     * evaluated by ProtectedRouteBoundary.
     */
    readonly accessPolicy:
        ProtectedRoutePolicy;

    /*
     * Heavy route implementation remains deferred.
     *
     * Composition must preserve this function reference
     * without invoking it during application bootstrap.
     */
    readonly lazy:
        NonNullable<
            RouteObject['lazy']
        >;
}

export function composeBusinessModuleRoutes(
    contributions:
        readonly BusinessModuleRouteContribution[],
): readonly RouteObject[] {
    return contributions.map(
        ({
            routeId,
            path,
            accessPolicy,
            lazy,
        }) => {
            /*
             * Router identity, authorization diagnostics,
             * navigation projection, and observability must
             * describe the same stable route.
             */
            if (
                accessPolicy.routeId
                    !== routeId
            ) {
                throw new Error(
                    `Business module route access policy routeId must match contributed routeId: expected ${routeId}, received ${accessPolicy.routeId}.`,
                );
            }

            return {
                id:
                    routeId,

                path,

                /*
                 * Keep the route permanently registered.
                 *
                 * Runtime authority determines whether its
                 * child Outlet may render; permission state
                 * never mutates the router topology.
                 */
                element: (
                    <ProtectedRouteBoundary
                        policy={
                            accessPolicy
                        }
                    >
                        <Outlet />
                    </ProtectedRouteBoundary>
                ),

                ErrorBoundary:
                    RouteErrorPage,

                children: [
                    {
                        index:
                            true,

                        lazy,
                    },
                ],
            };
        },
    );
}

function assertUniqueApplicationRouteIds(
    routes:
        readonly RouteObject[],
): void {
    const routeIds =
        new Set<string>();

    const visitRoute = (
        route:
            RouteObject,
    ): void => {
        if (
            route.id
                !== undefined
        ) {
            if (
                routeIds.has(
                    route.id,
                )
            ) {
                throw new Error(
                    `Duplicate application route ID: ${route.id}`,
                );
            }

            routeIds.add(
                route.id,
            );
        }

        for (
            const child
            of route.children ?? []
        ) {
            visitRoute(
                child,
            );
        }
    };

    for (
        const route
        of routes
    ) {
        visitRoute(
            route,
        );
    }
}

export function createApplicationRoutes(
    contributions:
        readonly BusinessModuleRouteContribution[],
): RouteObject[] {
    const businessModuleRoutes =
        composeBusinessModuleRoutes(
            contributions,
        );

    const routes: RouteObject[] = [
        {
            id:
                'protected-application',

            Component:
                ProtectedApplicationLifecycleBoundary,

            children: [
                {
                    id:
                        'protected-application-access',

                    Component:
                        ProtectedApplicationAccessBoundary,

                    children: [
                        {
                            id:
                                'authenticated-application-shell',

                            Component:
                                AuthenticatedApplicationShell,

                            children: [
                                {
                                    id:
                                        'root',

                                    path:
                                        '/',

                                    Component:
                                        App,

                                    ErrorBoundary:
                                        RouteErrorPage,
                                },

                                ...businessModuleRoutes,
                            ],
                        },
                    ],
                },
            ],
        },
        {
            id:
                'auth.login',

            path:
                '/login',

            Component:
                LoginRouteBoundary,

            ErrorBoundary:
                RouteErrorPage,
        },
        {
            id:
                'not-found',

            path:
                '*',

            Component:
                NotFoundPage,
        },
    ];

    assertUniqueApplicationRouteIds(
        routes,
    );

    return routes;
}

export const appRoutes =
    createApplicationRoutes(
        academicRouteContributions,
    );

export function createAppRouter() {
    return createBrowserRouter(
        appRoutes,
    );
}
