import {
    isValidElement,
} from 'react';
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
import {
    ProtectedRouteBoundary,
} from '@/app/routing/ProtectedRouteBoundary';
import type {
    ProtectedRoutePolicy,
} from '@/platform/routing';

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

        it('registers Academic Students as a static permission-protected business route', () => {
            const location =
                '/academic/students';

            const matches =
                matchRoutes(
                    appRoutes,
                    location,
                );

            const academicStudentsMatch =
                matches?.find(
                    (match) =>
                        match.route.id
                            === 'academic.students.index',
                );

            /*
             * RED:
             *
             * Infrastructure now understands canonical
             * per-route access policies, but the real
             * Academic Students contribution is not yet
             * registered in appRoutes.
             */
            expect(
                academicStudentsMatch,
            ).toBeDefined();

            if (
                academicStudentsMatch
                    === undefined
            ) {
                return;
            }

            expect(
                matchedRouteIds(
                    location,
                ),
            ).toEqual([
                'protected-application',
                'protected-application-access',
                'authenticated-application-shell',
                'academic.students.index',
            ]);

            const accessBoundary =
                academicStudentsMatch
                    .route
                    .element;

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
            ).toEqual({
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
