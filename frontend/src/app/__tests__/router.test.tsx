import {
    matchRoutes,
} from 'react-router';
import {
    describe,
    expect,
    it,
} from 'vitest';

import {
    appRoutes,
} from '@/app/router';

function matchedRouteIds(
    location:
        string,
): string[] {
    return (
        matchRoutes(
            appRoutes,
            location,
        )
            ?.map(
                (match) =>
                    match.route.id,
            )
            .filter(
                (
                    routeId,
                ): routeId is string =>
                    routeId !== undefined,
            )
        ?? []
    );
}

function matchedLeafRouteId(
    location:
        string,
): string | undefined {
    return matchedRouteIds(
        location,
    ).at(
        -1,
    );
}

describe(
    'Application router topology',
    () => {
        it('registers the canonical login route as a known application route', () => {
            expect(
                matchedLeafRouteId(
                    '/login',
                ),
            ).toBe(
                'auth.login',
            );
        });

        it('keeps unknown locations distinct from the canonical login route', () => {
            expect(
                matchedLeafRouteId(
                    '/definitely-not-a-route',
                ),
            ).toBe(
                'not-found',
            );
        });

        it('keeps the existing root route stable inside the authenticated application shell', () => {
            expect(
                matchedRouteIds(
                    '/',
                ),
            ).toEqual([
                'protected-application',
                'protected-application-access',
                'authenticated-application-shell',
                'root',
            ]);

            expect(
                matchedLeafRouteId(
                    '/',
                ),
            ).toBe(
                'root',
            );
        });

        it('keeps the public login route outside the authenticated application shell', () => {
            const routeIds =
                matchedRouteIds(
                    '/login',
                );

            expect(
                routeIds,
            ).toEqual([
                'auth.login',
            ]);

            expect(
                routeIds,
            ).not.toContain(
                'authenticated-application-shell',
            );

            expect(
                routeIds,
            ).not.toContain(
                'protected-application',
            );

            expect(
                routeIds,
            ).not.toContain(
                'protected-application-access',
            );
        });
    },
);
