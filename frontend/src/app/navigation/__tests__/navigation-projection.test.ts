import {
    describe,
    expect,
    it,
} from 'vitest';

import {
    projectApplicationNavigation,
    projectNavigationDefinitions,
} from '@/app/navigation/navigation-projection';
import type {
    BrowserAuthState,
} from '@/platform/auth';
import type {
    CapabilityState,
} from '@/platform/authorization';
import {
    defineApplicationNavigation,
} from '@/platform/navigation';
import type {
    MembershipContextState,
} from '@/platform/membership';
import {
    defineProtectedRoutePolicy,
    type ProtectedRoutePolicy,
} from '@/platform/routing';
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

const organizationalAssignmentId =
    '018f3b6a-7c20-7000-8000-000000000005';

const organizationId =
    '018f3b6a-7c20-7000-8000-000000000006';

const studentsView =
    'academic.students.view';

const authenticatedState:
    BrowserAuthState = {
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

const readyMembershipState:
    MembershipContextState = {
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

const readyTenantWorkspaceState:
    WorkspaceContextState = {
        status:
            'ready',

        context:
            readyMembershipState.context,

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

function readyTenantCapability(
    permissions:
        string[],
): CapabilityState {
    return {
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

const studentsNavigation =
    defineApplicationNavigation({
        id:
            'test.students',

        routeId:
            'test.students.index',

        label:
            'Students',

        destination:
            '/test/students',
    });

const studentsPolicy =
    defineProtectedRoutePolicy({
        routeId:
            'test.students.policy',

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

const organizationalNavigation =
    defineApplicationNavigation({
        id:
            'test.organizational',

        routeId:
            'test.organizational.index',

        label:
            'Organization',

        destination:
            '/test/organization',
    });

const organizationalPolicy =
    defineProtectedRoutePolicy({
        routeId:
            'test.organizational.policy',

        contextRequirement:
            'organizational',

        authorizationScope:
            'workspace',

        requiredPermissions:
            null,
    });

function resolverFor(
    routeId:
        string,
    policy:
        ProtectedRoutePolicy,
) {
    return (
        candidateRouteId:
            string,
    ): ProtectedRoutePolicy | null =>
        candidateRouteId
            === routeId
            ? policy
            : null;
}

describe(
    'Application navigation projection',
    () => {
        it('projects the current canonical protected root as visible without manufacturing a Capability dependency', () => {
            expect(
                projectApplicationNavigation({
                    authentication:
                        authenticatedState,

                    membership:
                        readyMembershipState,

                    workspace:
                        readyTenantWorkspaceState,

                    capability: {
                        status:
                            'unresolved',
                    },
                }),
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

        it('fails closed when a navigation destination has no canonical route policy', () => {
            expect(
                projectNavigationDefinitions({
                    definitions: [
                        studentsNavigation,
                    ],

                    resolvePolicy:
                        () => null,

                    authentication:
                        authenticatedState,

                    membership:
                        readyMembershipState,

                    workspace:
                        readyTenantWorkspaceState,

                    capability:
                        readyTenantCapability([
                            studentsView,
                        ]),
                }),
            ).toEqual([
                {
                    status:
                        'hidden',

                    navigation:
                        studentsNavigation,

                    reason:
                        'route-policy-missing',
                },
            ]);
        });

        it('hides a permission-protected destination when canonical READY authority denies it', () => {
            expect(
                projectNavigationDefinitions({
                    definitions: [
                        studentsNavigation,
                    ],

                    resolvePolicy:
                        resolverFor(
                            studentsNavigation.routeId,
                            studentsPolicy,
                        ),

                    authentication:
                        authenticatedState,

                    membership:
                        readyMembershipState,

                    workspace:
                        readyTenantWorkspaceState,

                    capability:
                        readyTenantCapability(
                            [],
                        ),
                }),
            ).toEqual([
                {
                    status:
                        'hidden',

                    navigation:
                        studentsNavigation,

                    reason:
                        'permission-denied',
                },
            ]);
        });

        it('shows a permission-protected destination only when canonical READY authority allows it', () => {
            expect(
                projectNavigationDefinitions({
                    definitions: [
                        studentsNavigation,
                    ],

                    resolvePolicy:
                        resolverFor(
                            studentsNavigation.routeId,
                            studentsPolicy,
                        ),

                    authentication:
                        authenticatedState,

                    membership:
                        readyMembershipState,

                    workspace:
                        readyTenantWorkspaceState,

                    capability:
                        readyTenantCapability([
                            studentsView,
                        ]),
                }),
            ).toEqual([
                {
                    status:
                        'visible',

                    navigation:
                        studentsNavigation,
                },
            ]);
        });

        it('hides an organizational destination while TENANT Workspace remains authoritative', () => {
            expect(
                projectNavigationDefinitions({
                    definitions: [
                        organizationalNavigation,
                    ],

                    resolvePolicy:
                        resolverFor(
                            organizationalNavigation.routeId,
                            organizationalPolicy,
                        ),

                    authentication:
                        authenticatedState,

                    membership:
                        readyMembershipState,

                    workspace:
                        readyTenantWorkspaceState,

                    capability:
                        readyTenantCapability(
                            [],
                        ),
                }),
            ).toEqual([
                {
                    status:
                        'hidden',

                    navigation:
                        organizationalNavigation,

                    reason:
                        'context-required',
                },
            ]);
        });

        it('keeps unresolved Capability authority hidden instead of treating pending authority as permission denial', () => {
            expect(
                projectNavigationDefinitions({
                    definitions: [
                        studentsNavigation,
                    ],

                    resolvePolicy:
                        resolverFor(
                            studentsNavigation.routeId,
                            studentsPolicy,
                        ),

                    authentication:
                        authenticatedState,

                    membership:
                        readyMembershipState,

                    workspace:
                        readyTenantWorkspaceState,

                    capability: {
                        status:
                            'unresolved',
                    },
                }),
            ).toEqual([
                {
                    status:
                        'hidden',

                    navigation:
                        studentsNavigation,

                    reason:
                        'authority-pending',
                },
            ]);
        });

        it('publishes an immutable projection snapshot', () => {
            const projection =
                projectApplicationNavigation({
                    authentication:
                        authenticatedState,

                    membership:
                        readyMembershipState,

                    workspace:
                        readyTenantWorkspaceState,

                    capability: {
                        status:
                            'unresolved',
                    },
                });

            expect(
                Object.isFrozen(
                    projection,
                ),
            ).toBe(
                true,
            );

            for (
                const item
                of projection
            ) {
                expect(
                    Object.isFrozen(
                        item,
                    ),
                ).toBe(
                    true,
                );
            }
        });
    },
);
