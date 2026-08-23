import type {
    BrowserAuthState,
} from '@/platform/auth';
import {
    createAuthorizationDecisionEvaluator,
    type CapabilityState,
    type CapabilityStateFailure,
} from '@/platform/authorization';
import type {
    MembershipContextState,
} from '@/platform/membership';
import type {
    WorkspaceContextState,
    WorkspaceSummary,
} from '@/platform/workspace';

import type {
    ProtectedRoutePolicy,
    RouteAuthorizationScope,
    RouteContextRequirement,
} from '@/platform/routing/route-policy';

export type ProtectedRoutePendingSource =
    | 'authentication'
    | 'membership'
    | 'workspace'
    | 'authorization';

export type ProtectedRoutePendingPhase =
    | 'membership-discovery'
    | 'membership-switch'
    | 'workspace-discovery'
    | 'workspace-switch'
    | 'workspace-recovery'
    | 'capability-load';

export type ProtectedRouteUnavailableSource =
    | 'authentication'
    | 'membership'
    | 'workspace'
    | 'authorization';

export interface PendingProtectedRouteAccessDecision {
    readonly status:
        'pending';

    readonly source:
        ProtectedRoutePendingSource;

    readonly phase?:
        ProtectedRoutePendingPhase;
}

export interface UnauthenticatedProtectedRouteAccessDecision {
    readonly status:
        'unauthenticated';
}

export interface MembershipRequiredProtectedRouteAccessDecision {
    readonly status:
        'membership-required';
}

export interface MembershipEmptyProtectedRouteAccessDecision {
    readonly status:
        'membership-empty';
}

export interface UnavailableProtectedRouteAccessDecision {
    readonly status:
        'unavailable';

    readonly source:
        ProtectedRouteUnavailableSource;

    readonly failure:
        CapabilityStateFailure;
}

export interface ContextRequiredProtectedRouteAccessDecision {
    readonly status:
        'context-required';

    readonly requiredContext:
        RouteContextRequirement;

    readonly currentWorkspace:
        WorkspaceSummary['type'];
}

export interface DeniedProtectedRouteAccessDecision {
    readonly status:
        'denied';
}

export interface AllowedProtectedRouteAccessDecision {
    readonly status:
        'allowed';
}

export type ProtectedRouteAccessDecision =
    | PendingProtectedRouteAccessDecision
    | UnauthenticatedProtectedRouteAccessDecision
    | MembershipRequiredProtectedRouteAccessDecision
    | MembershipEmptyProtectedRouteAccessDecision
    | UnavailableProtectedRouteAccessDecision
    | ContextRequiredProtectedRouteAccessDecision
    | DeniedProtectedRouteAccessDecision
    | AllowedProtectedRouteAccessDecision;

export interface ProtectedRouteAccessInput {
    readonly policy:
        ProtectedRoutePolicy;

    readonly authentication:
        BrowserAuthState;

    readonly membership:
        MembershipContextState;

    readonly workspace:
        WorkspaceContextState;

    readonly capability:
        CapabilityState;
}

function pending(
    source:
        ProtectedRoutePendingSource,
    phase?:
        ProtectedRoutePendingPhase,
): PendingProtectedRouteAccessDecision {
    return {
        status:
            'pending',

        source,

        ...(
            phase === undefined
                ? {}
                : {
                    phase,
                }
        ),
    };
}

function unavailable(
    source:
        ProtectedRouteUnavailableSource,
    failure:
        CapabilityStateFailure,
): UnavailableProtectedRouteAccessDecision {
    return {
        status:
            'unavailable',

        source,

        failure,
    };
}

function contextRequired(
    requiredContext:
        RouteContextRequirement,
    currentWorkspace:
        WorkspaceSummary['type'],
): ContextRequiredProtectedRouteAccessDecision {
    return {
        status:
            'context-required',

        requiredContext,

        currentWorkspace,
    };
}

function authorizationScopeMatchesWorkspace(
    authorizationScope:
        RouteAuthorizationScope,
    workspace:
        WorkspaceSummary,
): boolean {
    if (
        authorizationScope
            === 'tenant'
    ) {
        return workspace.type
            === 'TENANT';
    }

    return workspace.type
        !== 'TENANT';
}

function requiredContextForAuthorizationScope(
    authorizationScope:
        RouteAuthorizationScope,
): RouteContextRequirement {
    return authorizationScope
        === 'tenant'
        ? 'tenant'
        : 'organizational';
}

export function evaluateProtectedRouteAccess(
    input:
        ProtectedRouteAccessInput,
): ProtectedRouteAccessDecision {
    const {
        policy,
        authentication,
        membership,
        workspace,
        capability,
    } = input;

    /*
     * 1. Authentication authority.
     *
     * Transitional authentication states must never render
     * protected content and must never be interpreted as an
     * authoritative unauthenticated result.
     */
    switch (
        authentication.status
    ) {
        case 'unknown':
        case 'authenticating':
        case 'resolving-context':
        case 'logging-out':
            return pending(
                'authentication',
            );

        case 'anonymous':
            return {
                status:
                    'unauthenticated',
            };

        case 'unavailable':
            return unavailable(
                'authentication',
                authentication.failure,
            );

        case 'membership-context-required':
        case 'authenticated':
            break;
    }

    /*
     * 2. Membership/Tenant authority.
     *
     * membership-context-required deliberately reaches this
     * stage because Membership discovery/selection is the
     * required UX, not another login attempt.
     */
    switch (
        membership.status
    ) {
        case 'unresolved':
        case 'discovering':
            return pending(
                'membership',
                'membership-discovery',
            );

        case 'switching':
            return pending(
                'membership',
                'membership-switch',
            );

        case 'selection-required':
            return {
                status:
                    'membership-required',
            };

        case 'empty':
            return {
                status:
                    'membership-empty',
            };

        case 'unavailable':
            return unavailable(
                'membership',
                membership.failure,
            );

        case 'ready':
            break;
    }

    /*
     * Membership truth alone must never promote a browser
     * that still reports membership-context-required into
     * an authenticated protected route.
     *
     * This also makes temporary cross-runtime publication
     * ordering fail closed.
     */
    if (
        authentication.status
            !== 'authenticated'
    ) {
        return pending(
            'authentication',
        );
    }

    /*
     * 3. Workspace authority.
     *
     * Switching and stale-context recovery invalidate the
     * active Workspace for route authorization purposes.
     */
    switch (
        workspace.status
    ) {
        case 'unresolved':
        case 'discovering':
            return pending(
                'workspace',
                'workspace-discovery',
            );

        case 'switching':
            return pending(
                'workspace',
                'workspace-switch',
            );

        case 'recovering':
            return pending(
                'workspace',
                'workspace-recovery',
            );

        case 'unavailable':
            return unavailable(
                'workspace',
                workspace.failure,
            );

        case 'ready':
            break;
    }

    /*
     * An explicitly organizational route cannot be
     * evaluated from the TENANT Workspace.
     *
     * The evaluator does not choose another Workspace.
     */
    if (
        policy.contextRequirement
            === 'organizational'
        && workspace.current.type
            === 'TENANT'
    ) {
        return contextRequired(
            'organizational',
            workspace.current.type,
        );
    }

    /*
     * A protected route may intentionally have no
     * additional permission requirement.
     *
     * In that case authenticated Membership/Workspace
     * readiness is sufficient and Capability availability
     * is not manufactured into a new restriction.
     */
    if (
        policy.requiredPermissions
            === null
    ) {
        return {
            status:
                'allowed',
        };
    }

    /*
     * The current Workspace determines which Capability
     * projection family can be authoritative.
     *
     * Route policy never triggers an automatic Workspace
     * switch merely to obtain another permission set.
     */
    if (
        ! authorizationScopeMatchesWorkspace(
            policy.authorizationScope,
            workspace.current,
        )
    ) {
        return contextRequired(
            requiredContextForAuthorizationScope(
                policy.authorizationScope,
            ),
            workspace.current.type,
        );
    }

    /*
     * 4. Required Capability projection readiness.
     */
    switch (
        capability.status
    ) {
        case 'unresolved':
        case 'loading':
            return pending(
                'authorization',
                'capability-load',
            );

        case 'unavailable':
            return unavailable(
                'authorization',
                capability.failure,
            );

        case 'ready':
            break;
    }

    /*
     * 5. Permission policy.
     *
     * Exact matching, ALL/ANY semantics, and the prohibition
     * on role/superadmin bypass remain owned by the
     * Authorization decision layer.
     */
    const authorization =
        createAuthorizationDecisionEvaluator(
            capability,
        ).evaluate(
            policy.requiredPermissions,
        );

    switch (
        authorization.status
    ) {
        case 'pending':
            return pending(
                'authorization',
                'capability-load',
            );

        case 'unavailable':
            return unavailable(
                'authorization',
                authorization.failure,
            );

        case 'denied':
            return {
                status:
                    'denied',
            };

        case 'allowed':
            return {
                status:
                    'allowed',
            };
    }
}
