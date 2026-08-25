import {
    renderHook,
} from '@testing-library/react';
import {
    beforeEach,
    describe,
    expect,
    it,
    vi,
} from 'vitest';

import type {
    BrowserAuthState,
} from '@/platform/auth';
import type {
    CapabilityState,
} from '@/platform/authorization';
import type {
    MembershipContextState,
} from '@/platform/membership';
import type {
    WorkspaceContextState,
} from '@/platform/workspace';

const userId =
    '018f3b6a-7c20-7000-8000-000000000001';

const personId =
    '018f3b6a-7c20-7000-8000-000000000002';

const membershipId =
    '018f3b6a-7c20-7000-8000-000000000003';

const tenantId =
    '018f3b6a-7c20-7000-8000-000000000004';

const mocks =
    vi.hoisted<{
        authentication:
            BrowserAuthState;

        membership:
            MembershipContextState;

        workspace:
            WorkspaceContextState;

        capability:
            CapabilityState;
    }>(
        () => ({
            authentication: {
                status:
                    'unknown',
            },

            membership: {
                status:
                    'unresolved',
            },

            workspace: {
                status:
                    'unresolved',
            },

            capability: {
                status:
                    'unresolved',
            },
        }),
    );

vi.mock(
    '@/app/auth/BrowserAuthProvider',
    () => ({
        useBrowserAuthState:
            () =>
                mocks.authentication,
    }),
);

vi.mock(
    '@/app/membership/MembershipContextProvider',
    () => ({
        useMembershipContextState:
            () =>
                mocks.membership,
    }),
);

vi.mock(
    '@/app/workspace/WorkspaceContextProvider',
    () => ({
        useWorkspaceContextState:
            () =>
                mocks.workspace,
    }),
);

vi.mock(
    '@/app/authorization/CapabilityContextProvider',
    () => ({
        useCapabilityState:
            () =>
                mocks.capability,
    }),
);

import {
    useApplicationNavigationProjection,
} from '@/app/navigation/useApplicationNavigationProjection';

function configureReadyTenantAuthority(): void {
    mocks.authentication = {
        status:
            'authenticated',

        identity: {
            user: {
                id:
                    userId,

                email:
                    'member@example.test',
            },

            person: {
                id:
                    personId,

                name:
                    'EduCore Member',
            },

            membership: {
                id:
                    membershipId,

                status:
                    'ACTIVE',
            },

            tenant: {
                id:
                    tenantId,

                name:
                    'EduCore School',

                subdomain:
                    'educore-school',
            },
        },
    };

    mocks.membership = {
        status:
            'ready',

        memberships: [
            {
                membership_id:
                    membershipId,

                membership_status:
                    'ACTIVE',

                tenant_id:
                    tenantId,

                tenant_name:
                    'EduCore School',

                tenant_subdomain:
                    'educore-school',
            },
        ],

        context: {
            membership: {
                id:
                    membershipId,

                status:
                    'ACTIVE',
            },

            tenant: {
                id:
                    tenantId,

                name:
                    'EduCore School',

                subdomain:
                    'educore-school',
            },
        },

        failure:
            null,
    };

    mocks.workspace = {
        status:
            'ready',

        context: {
            membership: {
                id:
                    membershipId,

                status:
                    'ACTIVE',
            },

            tenant: {
                id:
                    tenantId,

                name:
                    'EduCore School',

                subdomain:
                    'educore-school',
            },
        },

        tenant: {
            id:
                tenantId,

            name:
                'EduCore School',
        },

        workspaces: [
            {
                type:
                    'TENANT',

                organizational_assignment_id:
                    null,

                organization_id:
                    null,

                organization_unit_id:
                    null,

                label:
                    'EduCore School',
            },
        ],

        current: {
            type:
                'TENANT',

            organizational_assignment_id:
                null,

            organization_id:
                null,

            organization_unit_id:
                null,

            label:
                'EduCore School',
        },

        failure:
            null,
    };

    /*
     * Root currently has no additional permission
     * requirement, so unresolved Capability authority
     * must not manufacture a restriction.
     */
    mocks.capability = {
        status:
            'unresolved',
    };
}

describe(
    'useApplicationNavigationProjection',
    () => {
        beforeEach(
            () => {
                mocks.authentication = {
                    status:
                        'unknown',
                };

                mocks.membership = {
                    status:
                        'unresolved',
                };

                mocks.workspace = {
                    status:
                        'unresolved',
                };

                mocks.capability = {
                    status:
                        'unresolved',
                };
            },
        );

        it('projects canonical navigation from already-published Provider snapshots', () => {
            configureReadyTenantAuthority();

            const {
                result,
            } =
                renderHook(
                    () =>
                        useApplicationNavigationProjection(),
                );

            expect(
                result.current,
            ).toEqual([
                {
                    status:
                        'visible',

                    navigation: {
                        id:
                            'application.home',

                        routeId:
                            'root',

                        label:
                            'Beranda',

                        destination:
                            '/',
                    },
                },
            ]);
        });

        it('preserves unresolved authentication as hidden pending navigation', () => {
            configureReadyTenantAuthority();

            mocks.authentication = {
                status:
                    'unknown',
            };

            const {
                result,
            } =
                renderHook(
                    () =>
                        useApplicationNavigationProjection(),
                );

            expect(
                result.current,
            ).toEqual([
                {
                    status:
                        'hidden',

                    navigation: {
                        id:
                            'application.home',

                        routeId:
                            'root',

                        label:
                            'Beranda',

                        destination:
                            '/',
                    },

                    reason:
                        'authority-pending',
                },
            ]);
        });

        it('reacts to external canonical snapshot changes without dispatching lifecycle work', () => {
            configureReadyTenantAuthority();

            const {
                result,
                rerender,
            } =
                renderHook(
                    () =>
                        useApplicationNavigationProjection(),
                );

            expect(
                result.current[
                    0
                ],
            ).toMatchObject({
                status:
                    'visible',
            });

            mocks.authentication = {
                status:
                    'anonymous',

                failure:
                    null,
            };

            rerender();

            expect(
                result.current[
                    0
                ],
            ).toMatchObject({
                status:
                    'hidden',

                reason:
                    'unauthenticated',
            });
        });

        it('returns the same projection reference while canonical snapshot references are unchanged', () => {
            configureReadyTenantAuthority();

            const {
                result,
                rerender,
            } =
                renderHook(
                    () =>
                        useApplicationNavigationProjection(),
                );

            const firstProjection =
                result.current;

            rerender();

            expect(
                result.current,
            ).toBe(
                firstProjection,
            );
        });
    },
);
