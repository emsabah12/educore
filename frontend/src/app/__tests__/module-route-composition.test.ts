import type {
    RouteObject,
} from 'react-router';
import {
    describe,
    expect,
    it,
    vi,
} from 'vitest';

import {
    RouteErrorPage,
} from '@/app/RouteErrorPage';
import * as applicationRouter from '@/app/router';

type LazyRouteFunction =
    NonNullable<
        RouteObject['lazy']
    >;

interface TestBusinessModuleRouteContribution {
    readonly routeId:
        string;

    readonly path:
        string;

    readonly lazy:
        LazyRouteFunction;
}

type ComposeBusinessModuleRoutes =
    (
        contributions:
            readonly TestBusinessModuleRouteContribution[],
    ) => readonly RouteObject[];

interface ApplicationRouterModuleComposition {
    readonly composeBusinessModuleRoutes?:
        ComposeBusinessModuleRoutes;
}

function LazyTestPage() {
    return null;
}

describe(
    'Business module route composition',
    () => {
        it('composes a static module error boundary around a lazy implementation without eagerly loading the module', () => {
            const lazyModule =
                vi.fn(
                    async () => ({
                        Component:
                            LazyTestPage,
                    }),
                );

            const routerContract =
                applicationRouter as
                    typeof applicationRouter
                    & ApplicationRouterModuleComposition;

            const compose =
                routerContract
                    .composeBusinessModuleRoutes;

            expect(
                compose,
            ).toBeTypeOf(
                'function',
            );

            if (
                compose === undefined
            ) {
                return;
            }

            const routes =
                compose([
                    {
                        routeId:
                            'test.module',

                        path:
                            'test-module',

                        lazy:
                            lazyModule,
                    },
                ]);

            expect(
                lazyModule,
            ).not.toHaveBeenCalled();

            expect(
                routes,
            ).toHaveLength(
                1,
            );

            const moduleBoundary =
                routes[0];

            expect(
                moduleBoundary?.id,
            ).toBe(
                'test.module',
            );

            expect(
                moduleBoundary?.path,
            ).toBe(
                'test-module',
            );

            expect(
                moduleBoundary?.ErrorBoundary,
            ).toBe(
                RouteErrorPage,
            );

            expect(
                moduleBoundary?.children,
            ).toHaveLength(
                1,
            );

            const lazyRoute =
                moduleBoundary
                    ?.children
                    ?.[0];

            expect(
                lazyRoute?.index,
            ).toBe(
                true,
            );

            expect(
                lazyRoute?.lazy,
            ).toBe(
                lazyModule,
            );

            expect(
                lazyModule,
            ).not.toHaveBeenCalled();
        });

        it('composes contributed business module routes beneath the authenticated application shell without eagerly loading them', () => {
            const lazyModule =
                vi.fn(
                    async () => ({
                        Component:
                            LazyTestPage,
                    }),
                );

            interface ApplicationRouteComposition {
                readonly createApplicationRoutes?:
                    (
                        contributions:
                            readonly TestBusinessModuleRouteContribution[],
                    ) => readonly RouteObject[];
            }

            const routerContract =
                applicationRouter as
                    typeof applicationRouter
                    & ApplicationRouteComposition;

            const createRoutes =
                routerContract
                    .createApplicationRoutes;

            expect(
                createRoutes,
            ).toBeTypeOf(
                'function',
            );

            if (
                createRoutes === undefined
            ) {
                return;
            }

            const routes =
                createRoutes([
                    {
                        routeId:
                            'test.module',

                        path:
                            'test-module',

                        lazy:
                            lazyModule,
                    },
                ]);

            expect(
                lazyModule,
            ).not.toHaveBeenCalled();

            const protectedApplication =
                routes.find(
                    (route) =>
                        route.id
                            === 'protected-application',
                );

            const protectedAccess =
                protectedApplication
                    ?.children
                    ?.find(
                        (route) =>
                            route.id
                                === 'protected-application-access',
                    );

            const shell =
                protectedAccess
                    ?.children
                    ?.find(
                        (route) =>
                            route.id
                                === 'authenticated-application-shell',
                    );

            expect(
                shell,
            ).toBeDefined();

            expect(
                shell?.children
                    ?.some(
                        (route) =>
                            route.id
                                === 'root',
                    ),
            ).toBe(
                true,
            );

            const moduleRoute =
                shell?.children
                    ?.find(
                        (route) =>
                            route.id
                                === 'test.module',
                    );

            expect(
                moduleRoute,
            ).toBeDefined();

            expect(
                moduleRoute?.path,
            ).toBe(
                'test-module',
            );

            expect(
                moduleRoute?.ErrorBoundary,
            ).toBe(
                RouteErrorPage,
            );

            expect(
                moduleRoute?.children
                    ?.[0]
                    ?.lazy,
            ).toBe(
                lazyModule,
            );

            expect(
                lazyModule,
            ).not.toHaveBeenCalled();
        });

        it('rejects a business module route ID that collides with the canonical application route tree', () => {
            const lazyModule =
                vi.fn(
                    async () => ({
                        Component:
                            LazyTestPage,
                    }),
                );

            expect(
                () =>
                    applicationRouter
                        .createApplicationRoutes([
                            {
                                routeId:
                                    'root',

                                path:
                                    'conflicting-root',

                                lazy:
                                    lazyModule,
                            },
                        ]),
            ).toThrow(
                'Duplicate application route ID: root',
            );

            expect(
                lazyModule,
            ).not.toHaveBeenCalled();
        });
    },
);
