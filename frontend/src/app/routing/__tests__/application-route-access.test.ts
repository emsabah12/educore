import {
    describe,
    expect,
    it,
} from 'vitest';

import {
    protectedApplicationPolicy,
    resolveApplicationRouteAccessPolicy,
} from '@/app/routing/application-route-access';
import {
    applicationNavigationCatalog,
} from '@/platform/navigation';

describe(
    'Application route access registry',
    () => {
        it('owns the canonical protected application policy exactly once', () => {
            expect(
                protectedApplicationPolicy,
            ).toEqual({
                routeId:
                    'app.protected-root',

                contextRequirement:
                    'tenant',

                authorizationScope:
                    'tenant',

                requiredPermissions:
                    null,
            });
        });

        it('resolves the registered root route to the canonical protected application policy', () => {
            expect(
                resolveApplicationRouteAccessPolicy(
                    'root',
                ),
            ).toBe(
                protectedApplicationPolicy,
            );
        });

        it('fails closed for an unknown route identity', () => {
            expect(
                resolveApplicationRouteAccessPolicy(
                    'unknown.route',
                ),
            ).toBeNull();
        });

        it('covers every current canonical navigation destination with a route access policy', () => {
            for (
                const navigation
                of applicationNavigationCatalog
            ) {
                expect(
                    resolveApplicationRouteAccessPolicy(
                        navigation.routeId,
                    ),
                ).not.toBeNull();
            }
        });

        it('keeps navigation metadata independent from authorization policy metadata', () => {
            const rootNavigation =
                applicationNavigationCatalog.find(
                    (navigation) =>
                        navigation.id
                            === 'application.home',
                );

            if (
                rootNavigation
                    === undefined
            ) {
                throw new Error(
                    'Expected the canonical application.home navigation definition.',
                );
            }

            const rootPolicy =
                resolveApplicationRouteAccessPolicy(
                    rootNavigation.routeId,
                );

            expect(
                rootNavigation,
            ).toEqual({
                id:
                    'application.home',

                routeId:
                    'root',

                label:
                    'Beranda',

                destination:
                    '/',
            });

            expect(
                rootPolicy,
            ).toBe(
                protectedApplicationPolicy,
            );
        });
    },
);