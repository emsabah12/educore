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

/*
 * A single stable array reference reused across renders —
 * required so the "same projection reference" test can prove
 * useMemo does not recompute when no upstream snapshot
 * reference actually changed.
 */
const STABLE_EMPTY_FEATURE_CODES: readonly string[] =
    Object.freeze([]);

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

        effectiveFeatureCodes:
            readonly string[] | undefined;
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

            effectiveFeatureCodes:
                undefined,
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

vi.mock(
    '@/app/navigation/api/use-tenant-effective-features-query',
    () => ({
        useTenantEffectiveFeaturesQuery:
            () => ({
                data:
                    mocks.effectiveFeatureCodes,
            }),
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

    mocks.effectiveFeatureCodes =
        STABLE_EMPTY_FEATURE_CODES;
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

                mocks.effectiveFeatureCodes =
                    undefined;
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
                {
                    status:
                        'hidden',

                    navigation: {
                        id:
                            'hr.workforce',

                        routeId:
                            'hr.workforce.index',

                        label:
                            'Kepegawaian',

                        destination:
                            '/hr/workforce',

                        requiredFeature:
                            'hr_module',
                    },

                    /*
                     * hr.workforce.index requires an
                     * organizational Workspace — the fixture's
                     * TENANT-type ready Workspace does not
                     * satisfy that, independent of feature
                     * availability.
                     */
                    reason:
                        'context-required',
                },
                {
                    status:
                        'hidden',

                    navigation: {
                        id:
                            'settings.tenant-roles',

                        routeId:
                            'settings.tenant-roles.index',

                        label:
                            'Role Kustom',

                        destination:
                            '/settings/roles',

                        requiredFeature:
                            'custom_roles',
                    },

                    /*
                     * settings.tenant-roles.index's tenant-scoped
                     * context requirement IS satisfied by this
                     * fixture, but its Capability projection is
                     * still unresolved, so the underlying
                     * permission check has not settled yet —
                     * this is the same authority-pending state
                     * as any other unresolved-Capability route,
                     * independent of feature availability.
                     */
                    reason:
                        'authority-pending',
                },
                {
                    status:
                        'hidden',

                    navigation: {
                        id:
                            'settings.organizations',

                        routeId:
                            'settings.organizations.index',

                        label:
                            'Organisasi',

                        destination:
                            '/settings/organizations',
                    },

                    /*
                     * settings.organizations.index's tenant-scoped
                     * context requirement IS satisfied by this
                     * fixture (same as settings.tenant-roles.index
                     * above), but its Capability projection is
                     * still unresolved, so the underlying
                     * organization.manage permission check has not
                     * settled yet — authority-pending, independent
                     * of feature availability (this destination has
                     * no requiredFeature at all).
                     */
                    reason:
                        'authority-pending',
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
                {
                    status:
                        'hidden',

                    navigation: {
                        id:
                            'hr.workforce',

                        routeId:
                            'hr.workforce.index',

                        label:
                            'Kepegawaian',

                        destination:
                            '/hr/workforce',

                        requiredFeature:
                            'hr_module',
                    },

                    reason:
                        'authority-pending',
                },
                {
                    status:
                        'hidden',

                    navigation: {
                        id:
                            'settings.tenant-roles',

                        routeId:
                            'settings.tenant-roles.index',

                        label:
                            'Role Kustom',

                        destination:
                            '/settings/roles',

                        requiredFeature:
                            'custom_roles',
                    },

                    reason:
                        'authority-pending',
                },
                {
                    status:
                        'hidden',

                    navigation: {
                        id:
                            'settings.organizations',

                        routeId:
                            'settings.organizations.index',

                        label:
                            'Organisasi',

                        destination:
                            '/settings/organizations',
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