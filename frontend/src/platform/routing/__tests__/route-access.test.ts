import {
    describe,
    expect,
    it,
} from 'vitest';

import type {
    BrowserApiFailure,
    BrowserApiResponseFailure,
} from '@/platform/api';
import type {
    BrowserAuthState,
} from '@/platform/auth';
import type {
    CapabilityState,
} from '@/platform/authorization';
import type {
    CanonicalMembershipContext,
    MembershipContextState,
    MembershipSummary,
} from '@/platform/membership';
import {
    defineProtectedRoutePolicy,
    evaluateProtectedRouteAccess,
} from '@/platform/routing';
import type {
    WorkspaceContextState,
    WorkspaceSummary,
} from '@/platform/workspace';

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

const roomsView =
    'dormitory.rooms.view';

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

const membershipContextFailure:
    BrowserApiResponseFailure = {
        ok:
            false,

        kind:
            'response',

        status:
            409,

        error: {
            status:
                'error',

            code:
                'BROWSER_MEMBERSHIP_CONTEXT_REQUIRED',

            message:
                'Membership context is required.',
        },
    };

const canonicalContext:
    CanonicalMembershipContext = {
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
    };

const membershipSummary:
    MembershipSummary = {
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
    };

const tenantWorkspace:
    WorkspaceSummary = {
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
    };

const organizationWorkspace:
    WorkspaceSummary = {
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
    };

function authenticatedState():
    BrowserAuthState {
    return {
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
}

function readyMembershipState():
    MembershipContextState {
    return {
        status:
            'ready',

        memberships: [
            membershipSummary,
        ],

        context:
            canonicalContext,

        failure:
            null,
    };
}

function readyWorkspaceState(
    current:
        WorkspaceSummary = tenantWorkspace,
): WorkspaceContextState {
    return {
        status:
            'ready',

        context:
            canonicalContext,

        tenant: {
            id:
                tenantId,

            name:
                'EduCore School',
        },

        workspaces: [
            tenantWorkspace,
            organizationWorkspace,
        ],

        current,

        failure:
            null,
    };
}

function tenantCapabilityState(
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

function workspaceCapabilityState(
    permissions:
        string[],
): CapabilityState {
    return {
        status:
            'ready',

        projection: {
            scope: {
                type:
                    'organization',

                tenant_id:
                    tenantId,

                membership_id:
                    membershipId,

                organizational_assignment_id:
                    organizationalAssignmentId,

                organization_id:
                    organizationId,

                organization_unit_id:
                    null,
            },

            is_global_superadmin:
                false,

            permissions,
        },
    };
}

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
                roomsView,
        },
    });

function baselineInput() {
    return {
        policy:
            tenantPolicy,

        authentication:
            authenticatedState(),

        membership:
            readyMembershipState(),

        workspace:
            readyWorkspaceState(),

        capability:
            tenantCapabilityState([
                studentsView,
            ]),
    };
}

describe(
    'Protected route access evaluator',
    () => {
        it('keeps unresolved authentication pending before evaluating downstream route authority', () => {
            expect(
                evaluateProtectedRouteAccess({
                    ...baselineInput(),

                    authentication: {
                        status:
                            'unknown',
                    },

                    capability: {
                        status:
                            'unavailable',

                        failure:
                            networkFailure,
                    },
                }),
            ).toEqual({
                status:
                    'pending',

                source:
                    'authentication',
            });
        });

        it('reports authoritative anonymous authentication without confusing it with pending bootstrap', () => {
            expect(
                evaluateProtectedRouteAccess({
                    ...baselineInput(),

                    authentication: {
                        status:
                            'anonymous',

                        failure:
                            null,
                    },
                }),
            ).toEqual({
                status:
                    'unauthenticated',
            });
        });

        it('preserves authentication unavailability before evaluating Membership or permissions', () => {
            expect(
                evaluateProtectedRouteAccess({
                    ...baselineInput(),

                    authentication: {
                        status:
                            'unavailable',

                        failure:
                            networkFailure,
                    },
                }),
            ).toEqual({
                status:
                    'unavailable',

                source:
                    'authentication',

                failure:
                    networkFailure,
            });
        });

        it('routes browser membership-context-required into Membership selection rather than unauthenticated access', () => {
            expect(
                evaluateProtectedRouteAccess({
                    ...baselineInput(),

                    authentication: {
                        status:
                            'membership-context-required',

                        failure:
                            membershipContextFailure,
                    },

                    membership: {
                        status:
                            'selection-required',

                        memberships: [
                            membershipSummary,
                        ],

                        failure:
                            null,
                    },
                }),
            ).toEqual({
                status:
                    'membership-required',
            });
        });

        it('keeps Membership switching pending instead of exposing the previous route authority', () => {
            expect(
                evaluateProtectedRouteAccess({
                    ...baselineInput(),

                    membership: {
                        status:
                            'switching',

                        memberships: [
                            membershipSummary,
                        ],

                        context:
                            canonicalContext,

                        target:
                            membershipSummary,
                    },
                }),
            ).toEqual({
                status:
                    'pending',

                source:
                    'membership',
            });
        });

        it('reports an authenticated Person with no Membership as a distinct route state', () => {
            expect(
                evaluateProtectedRouteAccess({
                    ...baselineInput(),

                    membership: {
                        status:
                            'empty',
                    },
                }),
            ).toEqual({
                status:
                    'membership-empty',
            });
        });

        it('preserves Membership discovery failure instead of treating it as authorization denial', () => {
            expect(
                evaluateProtectedRouteAccess({
                    ...baselineInput(),

                    membership: {
                        status:
                            'unavailable',

                        context:
                            canonicalContext,

                        failure:
                            networkFailure,
                    },
                }),
            ).toEqual({
                status:
                    'unavailable',

                source:
                    'membership',

                failure:
                    networkFailure,
            });
        });

        it('keeps Workspace recovery pending because the stale organizational Workspace is no longer authoritative', () => {
            expect(
                evaluateProtectedRouteAccess({
                    ...baselineInput(),

                    workspace: {
                        status:
                            'recovering',

                        context:
                            canonicalContext,

                        failure:
                            networkFailure,
                    },
                }),
            ).toEqual({
                status:
                    'pending',

                source:
                    'workspace',
            });
        });

        it('preserves Workspace unavailability before Capability evaluation', () => {
            expect(
                evaluateProtectedRouteAccess({
                    ...baselineInput(),

                    workspace: {
                        status:
                            'unavailable',

                        context:
                            canonicalContext,

                        failure:
                            networkFailure,
                    },
                }),
            ).toEqual({
                status:
                    'unavailable',

                source:
                    'workspace',

                failure:
                    networkFailure,
            });
        });

        it('returns context-required for an organizational route while TENANT Workspace is authoritative before checking permissions', () => {
            expect(
                evaluateProtectedRouteAccess({
                    ...baselineInput(),

                    policy:
                        organizationalPolicy,

                    workspace:
                        readyWorkspaceState(
                            tenantWorkspace,
                        ),

                    capability:
                        tenantCapabilityState([]),
                }),
            ).toEqual({
                status:
                    'context-required',

                requiredContext:
                    'organizational',

                currentWorkspace:
                    'TENANT',
            });
        });

        it('requires TENANT context when a tenant authorization scope is evaluated from an organizational Workspace', () => {
            expect(
                evaluateProtectedRouteAccess({
                    ...baselineInput(),

                    workspace:
                        readyWorkspaceState(
                            organizationWorkspace,
                        ),

                    capability:
                        workspaceCapabilityState([
                            studentsView,
                        ]),
                }),
            ).toEqual({
                status:
                    'context-required',

                requiredContext:
                    'tenant',

                currentWorkspace:
                    'ORGANIZATION',
            });
        });

        it('keeps unresolved Capability authority pending rather than denying a protected route', () => {
            expect(
                evaluateProtectedRouteAccess({
                    ...baselineInput(),

                    capability: {
                        status:
                            'unresolved',
                    },
                }),
            ).toEqual({
                status:
                    'pending',

                source:
                    'authorization',
            });
        });

        it('keeps loading Capability authority pending rather than denying a protected route', () => {
            expect(
                evaluateProtectedRouteAccess({
                    ...baselineInput(),

                    capability: {
                        status:
                            'loading',
                    },
                }),
            ).toEqual({
                status:
                    'pending',

                source:
                    'authorization',
            });
        });

        it('preserves Capability failure instead of translating unavailable authority to permission denial', () => {
            expect(
                evaluateProtectedRouteAccess({
                    ...baselineInput(),

                    capability: {
                        status:
                            'unavailable',

                        failure:
                            networkFailure,
                    },
                }),
            ).toEqual({
                status:
                    'unavailable',

                source:
                    'authorization',

                failure:
                    networkFailure,
            });
        });

        it('denies only after correct READY authority proves the required permission is absent', () => {
            expect(
                evaluateProtectedRouteAccess({
                    ...baselineInput(),

                    capability:
                        tenantCapabilityState([]),
                }),
            ).toEqual({
                status:
                    'denied',
            });
        });

        it('allows only after upstream context and exact permission authority are ready', () => {
            expect(
                evaluateProtectedRouteAccess(
                    baselineInput(),
                ),
            ).toEqual({
                status:
                    'allowed',
            });
        });

        it('allows a protected route with no additional permission requirement without manufacturing a Capability dependency', () => {
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
                evaluateProtectedRouteAccess({
                    ...baselineInput(),

                    policy,

                    capability: {
                        status:
                            'unavailable',

                        failure:
                            networkFailure,
                    },
                }),
            ).toEqual({
                status:
                    'allowed',
            });
        });
    },
);
