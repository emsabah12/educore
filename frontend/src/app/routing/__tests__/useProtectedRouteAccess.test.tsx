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
    BrowserApiFailure,
} from '@/platform/api';
import type {
    BrowserAuthState,
} from '@/platform/auth';
import type {
    CapabilityState,
} from '@/platform/authorization';
import type {
    MembershipContextState,
} from '@/platform/membership';
import {
    defineProtectedRoutePolicy,
} from '@/platform/routing';
import type {
    WorkspaceContextState,
} from '@/platform/workspace';

const mocks =
    vi.hoisted(
        () => ({
            authentication:
                undefined as
                    | BrowserAuthState
                    | undefined,

            membership:
                undefined as
                    | MembershipContextState
                    | undefined,

            workspace:
                undefined as
                    | WorkspaceContextState
                    | undefined,

            capability:
                undefined as
                    | CapabilityState
                    | undefined,
        }),
    );

vi.mock(
    '@/app/auth/BrowserAuthProvider',
    () => ({
        useBrowserAuthState:
            () => {
                if (
                    mocks.authentication
                        === undefined
                ) {
                    throw new Error(
                        'Authentication test state was not configured.',
                    );
                }

                return mocks.authentication;
            },
    }),
);

vi.mock(
    '@/app/membership/MembershipContextProvider',
    () => ({
        useMembershipContextState:
            () => {
                if (
                    mocks.membership
                        === undefined
                ) {
                    throw new Error(
                        'Membership test state was not configured.',
                    );
                }

                return mocks.membership;
            },
    }),
);

vi.mock(
    '@/app/workspace/WorkspaceContextProvider',
    () => ({
        useWorkspaceContextState:
            () => {
                if (
                    mocks.workspace
                        === undefined
                ) {
                    throw new Error(
                        'Workspace test state was not configured.',
                    );
                }

                return mocks.workspace;
            },
    }),
);

vi.mock(
    '@/app/authorization/CapabilityContextProvider',
    () => ({
        useCapabilityState:
            () => {
                if (
                    mocks.capability
                        === undefined
                ) {
                    throw new Error(
                        'Capability test state was not configured.',
                    );
                }

                return mocks.capability;
            },
    }),
);

import {
    useProtectedRouteAccess,
} from '@/app/routing/useProtectedRouteAccess';

const membershipId =
    '018f3b6a-7c20-7abc-8def-1234567890ab';

const tenantId =
    '018f3b6a-7c20-7bcd-8def-1234567890ab';

const userId =
    '018f3b6a-7c20-7cde-8def-1234567890ab';

const personId =
    '018f3b6a-7c20-7def-8def-1234567890ab';

const organizationalAssignmentId =
    '018f3b6a-7c20-7eee-8def-1234567890ab';

const organizationId =
    '018f3b6a-7c20-7fff-8def-1234567890ab';

const studentsView =
    'academic.students.view';

const networkFailure:
    BrowserApiFailure = {
        ok:
            false,

        kind:
            'network',

        cause:
            new Error(
                'offline',
            ),
    };

const tenantPolicy =
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
                studentsView,
        },
    });

const organizationalPolicy =
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

function configureReadyTenantContext(
    permissions:
        string[] = [
            studentsView,
        ],
): void {
    mocks.authentication = {
        status:
            'authenticated',

        identity: {
            user: {
                id:
                    userId,

                email:
                    'user@example.test',
            },

            person: {
                id:
                    personId,

                name:
                    'EduCore User',
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
                    'educore',
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
                    'educore',
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
                    'educore',
            },
        },

        failure:
            null,
    };

    mocks.workspace = {
        status:
            'ready',

        context:
            mocks.membership.context,

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
            {
                type:
                    'ORGANIZATION',

                organizational_assignment_id:
                    organizationalAssignmentId,

                organization_id:
                    organizationId,

                organization_unit_id:
                    null,

                label:
                    'EduCore Organization',
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

    mocks.capability = {
        status:
            'ready',

        projection: {
            scope: {
                type:
                    'tenant',

                tenant_id:
                    tenantId,

                membership_id:
                    membershipId,
            },

            is_global_superadmin:
                false,

            permissions,
        },
    };
}

function configureIdentityAuthenticated(): void {
    mocks.authentication = {
        status:
            'identity-authenticated',

        identity: {
            context_type:
                'identity',

            user: {
                id:
                    userId,

                name:
                    'EduCore User',

                email:
                    'user@example.test',

                username:
                    'user',
            },

            platform: {
                is_superadmin:
                    false,
            },
        },
    };
}

describe(
    'useProtectedRouteAccess',
    () => {
        beforeEach(
            () => {
                configureReadyTenantContext();
            },
        );

        it('returns allowed from already-published canonical route authority', () => {
            const {
                result,
            } =
                renderHook(
                    () =>
                        useProtectedRouteAccess(
                            tenantPolicy,
                        ),
                );

            expect(
                result.current,
            ).toEqual({
                status:
                    'allowed',
            });
        });

        it('preserves unresolved authentication as pending instead of rendering downstream authorization', () => {
            mocks.authentication = {
                status:
                    'unknown',
            };

            mocks.capability = {
                status:
                    'unavailable',

                failure:
                    networkFailure,
            };

            const {
                result,
            } =
                renderHook(
                    () =>
                        useProtectedRouteAccess(
                            tenantPolicy,
                        ),
                );

            expect(
                result.current,
            ).toEqual({
                status:
                    'pending',

                source:
                    'authentication',
            });
        });

        it('preserves authoritative anonymous authentication as unauthenticated', () => {
            mocks.authentication = {
                status:
                    'anonymous',

                failure:
                    null,
            };

            const {
                result,
            } =
                renderHook(
                    () =>
                        useProtectedRouteAccess(
                            tenantPolicy,
                        ),
                );

            expect(
                result.current,
            ).toEqual({
                status:
                    'unauthenticated',
            });
        });

        it('projects Identity-authenticated Membership selection as membership-required instead of returning to login', () => {
            if (
                mocks.membership
                    === undefined
                || mocks.membership.status
                    !== 'ready'
            ) {
                throw new Error(
                    'Expected ready Membership test fixture.',
                );
            }

            const memberships =
                mocks.membership
                    .memberships;

            configureIdentityAuthenticated();

            mocks.membership = {
                status:
                    'selection-required',

                memberships,

                failure:
                    null,
            };

            const {
                result,
            } =
                renderHook(
                    () =>
                        useProtectedRouteAccess(
                            tenantPolicy,
                        ),
                );

            /*
             * Global User authentication is authoritative.
             *
             * Missing active Membership/Tenant context must
             * become explicit Membership-selection UX,
             * never another credential-entry cycle.
             */
            expect(
                result.current,
            ).toEqual({
                status:
                    'membership-required',
            });

            expect(
                result.current.status,
            ).not.toBe(
                'unauthenticated',
            );
        });

        it('projects Identity-authenticated zero-Membership state as membership-empty instead of returning to login', () => {
            configureIdentityAuthenticated();

            mocks.membership = {
                status:
                    'empty',
            };

            const {
                result,
            } =
                renderHook(
                    () =>
                        useProtectedRouteAccess(
                            tenantPolicy,
                        ),
                );

            /*
             * A User with zero Memberships still has valid
             * global Identity authority.
             *
             * The protected application must expose the
             * canonical empty-Membership state rather than
             * treating the User as anonymous or inventing
             * Tenant authorization.
             */
            expect(
                result.current,
            ).toEqual({
                status:
                    'membership-empty',
            });

            expect(
                result.current.status,
            ).not.toBe(
                'unauthenticated',
            );
        });

        it('preserves Membership selection as membership-required instead of permission denial', () => {
            if (
                mocks.membership
                    === undefined
                || mocks.membership.status
                    !== 'ready'
            ) {
                throw new Error(
                    'Expected ready Membership test fixture.',
                );
            }

            mocks.membership = {
                status:
                    'selection-required',

                memberships:
                    mocks.membership
                        .memberships,

                failure:
                    null,
            };

            const {
                result,
            } =
                renderHook(
                    () =>
                        useProtectedRouteAccess(
                            tenantPolicy,
                        ),
                );

            expect(
                result.current,
            ).toEqual({
                status:
                    'membership-required',
            });
        });

        it('preserves organizational context requirement while TENANT Workspace is current', () => {
            const {
                result,
            } =
                renderHook(
                    () =>
                        useProtectedRouteAccess(
                            organizationalPolicy,
                        ),
                );

            expect(
                result.current,
            ).toEqual({
                status:
                    'context-required',

                requiredContext:
                    'organizational',

                currentWorkspace:
                    'TENANT',
            });
        });

        it('rerenders from new external snapshots without dispatching any lifecycle operation', () => {
            const {
                result,
                rerender,
            } =
                renderHook(
                    () =>
                        useProtectedRouteAccess(
                            tenantPolicy,
                        ),
                );

            expect(
                result.current,
            ).toEqual({
                status:
                    'allowed',
            });

            mocks.capability = {
                status:
                    'loading',
            };

            rerender();

            expect(
                result.current,
            ).toEqual({
                status:
                    'pending',

                source:
                    'authorization',

                phase:
                    'capability-load',
            });

            mocks.capability = {
                status:
                    'ready',

                projection: {
                    scope: {
                        type:
                            'tenant',

                        tenant_id:
                            tenantId,

                        membership_id:
                            membershipId,
                    },

                    is_global_superadmin:
                        false,

                    permissions:
                        [],
                },
            };

            rerender();

            expect(
                result.current,
            ).toEqual({
                status:
                    'denied',
            });
        });

        it('preserves route authority unavailability rather than converting it to access denied', () => {
            mocks.capability = {
                status:
                    'unavailable',

                failure:
                    networkFailure,
            };

            const {
                result,
            } =
                renderHook(
                    () =>
                        useProtectedRouteAccess(
                            tenantPolicy,
                        ),
                );

            expect(
                result.current,
            ).toEqual({
                status:
                    'unavailable',

                source:
                    'authorization',

                failure:
                    networkFailure,
            });
        });
    },
);
