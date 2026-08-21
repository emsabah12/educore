import {
    describe,
    expect,
    it,
} from 'vitest';

import {
    defineProtectedRoutePolicy,
} from '@/platform/routing';

describe(
    'Protected route policy contract',
    () => {
        it('defines a tenant-scoped protected route using canonical permission metadata', () => {
            const policy =
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

            expect(
                policy,
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

        it('defines an organizational route without choosing a concrete Workspace', () => {
            const policy =
                defineProtectedRoutePolicy({
                    routeId:
                        'dormitory.rooms.index',

                    contextRequirement:
                        'organizational',

                    authorizationScope:
                        'workspace',

                    requiredPermissions: {
                        mode:
                            'single',

                        permission:
                            'dormitory.rooms.view',
                    },
                });

            expect(
                policy.contextRequirement,
            ).toBe(
                'organizational',
            );

            expect(
                policy.authorizationScope,
            ).toBe(
                'workspace',
            );
        });

        it('reuses explicit ALL permission requirement semantics', () => {
            const policy =
                defineProtectedRoutePolicy({
                    routeId:
                        'academic.grades.manage',

                    contextRequirement:
                        'tenant',

                    authorizationScope:
                        'tenant',

                    requiredPermissions: {
                        mode:
                            'all',

                        permissions: [
                            'academic.grades.view',
                            'academic.grades.write',
                        ],
                    },
                });

            expect(
                policy.requiredPermissions,
            ).toEqual({
                mode:
                    'all',

                permissions: [
                    'academic.grades.view',
                    'academic.grades.write',
                ],
            });
        });

        it('supports a protected route with no additional permission requirement', () => {
            const policy =
                defineProtectedRoutePolicy({
                    routeId:
                        'core.dashboard',

                    contextRequirement:
                        'tenant',

                    authorizationScope:
                        'tenant',

                    requiredPermissions:
                        null,
                });

            expect(
                policy.requiredPermissions,
            ).toBeNull();
        });

        it('rejects an empty route identity instead of creating anonymous policy metadata', () => {
            expect(
                () =>
                    defineProtectedRoutePolicy({
                        routeId:
                            '   ',

                        contextRequirement:
                            'tenant',

                        authorizationScope:
                            'tenant',

                        requiredPermissions:
                            null,
                    }),
            ).toThrow(
                'EduCore protected route policy requires a non-empty stable routeId.',
            );
        });

        it('snapshots permission collections instead of observing later metadata mutation', () => {
            const permissions = [
                'dormitory.rooms.view',
            ];

            const policy =
                defineProtectedRoutePolicy({
                    routeId:
                        'dormitory.rooms.manage',

                    contextRequirement:
                        'organizational',

                    authorizationScope:
                        'workspace',

                    requiredPermissions: {
                        mode:
                            'all',

                        permissions,
                    },
                });

            permissions.push(
                'dormitory.rooms.manage',
            );

            expect(
                policy.requiredPermissions,
            ).toEqual({
                mode:
                    'all',

                permissions: [
                    'dormitory.rooms.view',
                ],
            });
        });
    },
);
