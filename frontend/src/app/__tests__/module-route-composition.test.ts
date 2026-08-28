import {
    isValidElement,
} from 'react';
import {
    Outlet,
    type RouteObject,
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
import {
    ProtectedRouteBoundary,
} from '@/app/routing/ProtectedRouteBoundary';
import {
    defineProtectedRoutePolicy,
    type ProtectedRoutePolicy,
} from '@/platform/routing';

type LazyRouteFunction =
    NonNullable<
        RouteObject['lazy']
    >;

interface TestBusinessModuleRouteContribution {
    readonly routeId:
        string;

    readonly path:
        string;

    /*
     * Every business route contributes its canonical access
     * policy together with its static route definition.
     *
     * Permission authority remains runtime-owned; this is only
     * immutable route metadata.
     */
    readonly accessPolicy:
        ProtectedRoutePolicy;

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

function createTestAccessPolicy(
    routeId:
        string,
    permission =
        'test.module.view',
): ProtectedRoutePolicy {
    return defineProtectedRoutePolicy({
        routeId,

        contextRequirement:
            'tenant',

        authorizationScope:
            'tenant',

        requiredPermissions: {
            mode:
                'single',

            permission,
        },
    });
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

                        accessPolicy:
                            createTestAccessPolicy(
                                'test.module',
                            ),

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

                        accessPolicy:
                            createTestAccessPolicy(
                                'test.module',
                            ),

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

        it('composes canonical per-route access policy around the lazy business module implementation', () => {
            const lazyModule =
                vi.fn(
                    async () => ({
                        Component:
                            LazyTestPage,
                    }),
                );

            const accessPolicy =
                createTestAccessPolicy(
                    'academic.students.index',
                    'academic.students.view',
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
                            'academic.students.index',

                        path:
                            'academic/students',

                        accessPolicy,

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

            const moduleRoute =
                routes[0];

            expect(
                moduleRoute?.id,
            ).toBe(
                'academic.students.index',
            );

            expect(
                moduleRoute?.path,
            ).toBe(
                'academic/students',
            );

            /*
             * The static route itself owns the access boundary.
             *
             * Route existence therefore remains independent of
             * the current permission projection while runtime
             * access remains fail-closed.
             */
            const accessBoundary =
                moduleRoute?.element;

            expect(
                isValidElement(
                    accessBoundary,
                ),
            ).toBe(
                true,
            );

            if (
                ! isValidElement<{
                    readonly policy:
                        ProtectedRoutePolicy;

                    readonly children?:
                        unknown;
                }>(
                    accessBoundary,
                )
            ) {
                return;
            }

            expect(
                accessBoundary.type,
            ).toBe(
                ProtectedRouteBoundary,
            );

            expect(
                accessBoundary.props
                    .policy,
            ).toEqual(
                accessPolicy,
            );

            const outlet =
                accessBoundary.props
                    .children;

            expect(
                isValidElement(
                    outlet,
                ),
            ).toBe(
                true,
            );

            if (
                ! isValidElement(
                    outlet,
                )
            ) {
                return;
            }

            expect(
                outlet.type,
            ).toBe(
                Outlet,
            );

            expect(
                moduleRoute?.children,
            ).toHaveLength(
                1,
            );

            expect(
                moduleRoute
                    ?.children
                    ?.[0]
                    ?.index,
            ).toBe(
                true,
            );

            expect(
                moduleRoute
                    ?.children
                    ?.[0]
                    ?.lazy,
            ).toBe(
                lazyModule,
            );

            expect(
                lazyModule,
            ).not.toHaveBeenCalled();
        });

        it('rejects a business module access policy whose stable routeId differs from the contributed routeId', () => {
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

            const mismatchedPolicy =
                createTestAccessPolicy(
                    'academic.students.show',
                    'academic.students.view',
                );

            expect(
                () =>
                    compose([
                        {
                            routeId:
                                'academic.students.index',

                            path:
                                'academic/students',

                            accessPolicy:
                                mismatchedPolicy,

                            lazy:
                                lazyModule,
                        },
                    ]),
            ).toThrow(
                /routeId.*match/iu,
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

            /*
             * This RED test file intentionally describes the
             * prospective business-route contract before the
             * production interface implements it.
             *
             * Use the same test-side structural contract as
             * the composition tests above so TypeScript can
             * validate the RED harness independently from the
             * missing production property.
             */
            interface ApplicationRouteComposition {
                readonly createApplicationRoutes:
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
                () =>
                    createRoutes([
                        {
                            routeId:
                                'root',

                            path:
                                'conflicting-root',

                            accessPolicy:
                                createTestAccessPolicy(
                                    'root',
                                ),

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
