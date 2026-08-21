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

function matchedLeafRouteId(
    location:
        string,
): string | undefined {
    const matches =
        matchRoutes(
            appRoutes,
            location,
        );

    return matches
        ?.at(-1)
        ?.route
        .id;
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

        it('keeps the existing root route stable', () => {
            expect(
                matchedLeafRouteId(
                    '/',
                ),
            ).toBe(
                'root',
            );
        });
    },
);
