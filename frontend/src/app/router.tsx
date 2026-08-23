import {
    createBrowserRouter,
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
    ProtectedApplicationLifecycleBoundary,
} from '@/app/routing/ProtectedApplicationLifecycleBoundary';
import {
    AuthenticatedApplicationShell,
} from '@/app/shell/AuthenticatedApplicationShell';

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
            lazy,
        }) => ({
            id:
                routeId,

            path,

            ErrorBoundary:
                RouteErrorPage,

            children: [
                {
                    index:
                        true,

                    lazy,
                },
            ],
        }),
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
    createApplicationRoutes([]);

export function createAppRouter() {
    return createBrowserRouter(
        appRoutes,
    );
}
