import type {
    BrowserApiFailure,
} from '@/platform/api';
import type {
    CanonicalMembershipContext,
} from '@/platform/membership';

import type {
    WorkspaceDiscoveryData,
    WorkspaceDiscoveryTenant,
    WorkspaceSummary,
} from '@/platform/workspace/contract';

export interface UnresolvedWorkspaceContextState {
    readonly status:
        'unresolved';
}

export interface DiscoveringWorkspaceContextState {
    readonly status:
        'discovering';

    readonly context:
        CanonicalMembershipContext;
}

export interface ReadyWorkspaceContextState {
    readonly status:
        'ready';

    readonly context:
        CanonicalMembershipContext;

    readonly tenant:
        WorkspaceDiscoveryTenant;

    readonly workspaces:
        readonly WorkspaceSummary[];

    readonly current:
        WorkspaceSummary;

    readonly failure:
        BrowserApiFailure | null;
}

export interface SwitchingWorkspaceContextState {
    readonly status:
        'switching';

    readonly context:
        CanonicalMembershipContext;

    readonly tenant:
        WorkspaceDiscoveryTenant;

    readonly workspaces:
        readonly WorkspaceSummary[];

    readonly current:
        WorkspaceSummary;

    readonly target:
        WorkspaceSummary;
}

export interface RecoveringWorkspaceContextState {
    readonly status:
        'recovering';

    readonly context:
        CanonicalMembershipContext;

    readonly failure:
        BrowserApiFailure;
}

export interface UnavailableWorkspaceContextState {
    readonly status:
        'unavailable';

    readonly context:
        CanonicalMembershipContext;

    readonly failure:
        BrowserApiFailure;
}

export type WorkspaceContextState =
    | UnresolvedWorkspaceContextState
    | DiscoveringWorkspaceContextState
    | ReadyWorkspaceContextState
    | SwitchingWorkspaceContextState
    | RecoveringWorkspaceContextState
    | UnavailableWorkspaceContextState;

export type WorkspaceContextAction =
    | {
        readonly type:
            'DISCOVERY_STARTED';

        readonly context:
            CanonicalMembershipContext;
    }
    | {
        readonly type:
            'DISCOVERY_READY';

        readonly discovery:
            WorkspaceDiscoveryData;
    }
    | {
        readonly type:
            'DISCOVERY_UNAVAILABLE';

        readonly failure:
            BrowserApiFailure;
    }
    | {
        readonly type:
            'SWITCH_STARTED';

        readonly target:
            WorkspaceSummary;
    }
    | {
        readonly type:
            'TARGET_VERIFIED';
    }
    | {
        readonly type:
            'SWITCH_FAILED';

        readonly failure:
            BrowserApiFailure;
    }
    | {
        readonly type:
            'RECOVERY_STARTED';

        readonly failure:
            BrowserApiFailure;
    }
    | {
        readonly type:
            'RESET';
    };

export function createInitialWorkspaceContextState():
    UnresolvedWorkspaceContextState {
    return {
        status:
            'unresolved',
    };
}

function invalidTransition(
    state: WorkspaceContextState,
    action: WorkspaceContextAction,
): never {
    throw new Error(
        [
            'Invalid EduCore WorkspaceContext transition:',
            state.status,
            '->',
            action.type,
        ].join(' '),
    );
}

function workspaceIdentityMatches(
    first: WorkspaceSummary,
    second: WorkspaceSummary,
): boolean {
    if (
        first.type
            !== second.type
    ) {
        return false;
    }

    if (
        first.type
            === 'TENANT'
        && second.type
            === 'TENANT'
    ) {
        return true;
    }

    if (
        first.type
            === 'ORGANIZATION'
        && second.type
            === 'ORGANIZATION'
    ) {
        return (
            first.organizational_assignment_id
                === second.organizational_assignment_id
        );
    }

    if (
        first.type
            === 'ORGANIZATION_UNIT'
        && second.type
            === 'ORGANIZATION_UNIT'
    ) {
        return (
            first.organizational_assignment_id
                === second.organizational_assignment_id
        );
    }

    return false;
}

function findWorkspace(
    workspaces:
        readonly WorkspaceSummary[],
    target:
        WorkspaceSummary,
): WorkspaceSummary | undefined {
    return workspaces.find(
        (workspace) =>
            workspaceIdentityMatches(
                workspace,
                target,
            ),
    );
}

export function validateWorkspaceDiscovery(
    context:
        CanonicalMembershipContext,
    discovery:
        WorkspaceDiscoveryData,
): WorkspaceSummary {
    if (
        discovery.tenant.id
            !== context.tenant.id
    ) {
        throw new Error(
            'EduCore WorkspaceContext discovery Tenant does not match the canonical Membership/Tenant context.',
        );
    }

    const tenantWorkspaces =
        discovery.workspaces.filter(
            (workspace) =>
                workspace.type
                    === 'TENANT',
        );

    if (
        tenantWorkspaces.length
            !== 1
    ) {
        throw new Error(
            'EduCore WorkspaceContext discovery must contain exactly one TENANT Workspace.',
        );
    }

    const assignmentIds =
        new Set<string>();

    for (
        const workspace
        of discovery.workspaces
    ) {
        if (
            workspace.type
                === 'TENANT'
        ) {
            continue;
        }

        const assignmentId =
            workspace
                .organizational_assignment_id;

        if (
            assignmentIds.has(
                assignmentId,
            )
        ) {
            throw new Error(
                'EduCore WorkspaceContext discovery contains duplicate organizational assignment locators.',
            );
        }

        assignmentIds.add(
            assignmentId,
        );
    }

    const tenantWorkspace =
        tenantWorkspaces[0];

    if (
        tenantWorkspace
            === undefined
    ) {
        throw new Error(
            'EduCore WorkspaceContext discovery must contain exactly one TENANT Workspace.',
        );
    }

    return tenantWorkspace;
}

export function workspaceContextReducer(
    state: WorkspaceContextState,
    action: WorkspaceContextAction,
): WorkspaceContextState {
    switch (action.type) {
        case 'DISCOVERY_STARTED':
            /*
             * Discovery may replace unresolved, stale,
             * unavailable, or previous stable Workspace
             * truth.
             *
             * An active Workspace switch cannot be
             * interrupted at reducer level. Runtime
             * orchestration owns cancellation/revisions.
             */
            if (
                state.status
                    === 'switching'
            ) {
                return invalidTransition(
                    state,
                    action,
                );
            }

            return {
                status:
                    'discovering',
                context:
                    action.context,
            };

        case 'DISCOVERY_READY': {
            if (
                state.status
                    !== 'discovering'
            ) {
                return invalidTransition(
                    state,
                    action,
                );
            }

            const tenantWorkspace =
                validateWorkspaceDiscovery(
                    state.context,
                    action.discovery,
                );

            return {
                status:
                    'ready',
                context:
                    state.context,
                tenant:
                    action.discovery.tenant,
                workspaces:
                    action.discovery.workspaces,
                current:
                    tenantWorkspace,
                failure:
                    null,
            };
        }

        case 'DISCOVERY_UNAVAILABLE':
            if (
                state.status
                    !== 'discovering'
            ) {
                return invalidTransition(
                    state,
                    action,
                );
            }

            return {
                status:
                    'unavailable',
                context:
                    state.context,
                failure:
                    action.failure,
            };

        case 'SWITCH_STARTED': {
            if (
                state.status
                    !== 'ready'
            ) {
                return invalidTransition(
                    state,
                    action,
                );
            }

            const target =
                findWorkspace(
                    state.workspaces,
                    action.target,
                );

            if (
                target
                    === undefined
            ) {
                throw new Error(
                    'EduCore WorkspaceContext switch target is not available in the current Workspace catalog.',
                );
            }

            if (
                workspaceIdentityMatches(
                    state.current,
                    target,
                )
            ) {
                return state;
            }

            return {
                status:
                    'switching',
                context:
                    state.context,
                tenant:
                    state.tenant,
                workspaces:
                    state.workspaces,
                current:
                    state.current,
                target,
            };
        }

        case 'TARGET_VERIFIED':
            if (
                state.status
                    !== 'switching'
            ) {
                return invalidTransition(
                    state,
                    action,
                );
            }

            return {
                status:
                    'ready',
                context:
                    state.context,
                tenant:
                    state.tenant,
                workspaces:
                    state.workspaces,
                current:
                    state.target,
                failure:
                    null,
            };

        case 'SWITCH_FAILED':
            if (
                state.status
                    !== 'switching'
            ) {
                return invalidTransition(
                    state,
                    action,
                );
            }

            return {
                status:
                    'ready',
                context:
                    state.context,
                tenant:
                    state.tenant,
                workspaces:
                    state.workspaces,
                current:
                    state.current,
                failure:
                    action.failure,
            };

        case 'RECOVERY_STARTED':
            if (
                state.status
                    !== 'ready'
            ) {
                return invalidTransition(
                    state,
                    action,
                );
            }

            /*
             * A stale current organizational context is no
             * longer authoritative. Do not expose the old
             * Workspace catalog/current Workspace while
             * recovery is active.
             */
            return {
                status:
                    'recovering',
                context:
                    state.context,
                failure:
                    action.failure,
            };

        case 'RESET':
            return createInitialWorkspaceContextState();
    }
}
