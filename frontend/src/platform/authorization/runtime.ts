import type {
    CanonicalMembershipContext,
} from '@/platform/membership/contract';
import type {
    WorkspaceSummary,
} from '@/platform/workspace/contract';

import type {
    CapabilityProjectionOperations,
} from '@/platform/authorization/operations';
import {
    capabilityReducer,
    createInitialCapabilityState,
    type CapabilityAction,
    type CapabilityState,
} from '@/platform/authorization/state';
import {
    validateTenantCapabilityProjection,
    validateWorkspaceCapabilityProjection,
} from '@/platform/authorization/validation';
import {
    createWorkspaceCapabilityScopeExpectation,
} from '@/platform/authorization/workspace-scope';

export interface CapabilityRuntimeOptions {
    readonly signal?:
        AbortSignal;
}

export interface CapabilityMembershipSourceState {
    readonly status:
        string;

    readonly context?:
        CanonicalMembershipContext | null;
}

export interface CapabilityWorkspaceSourceState {
    readonly status:
        string;

    readonly context?:
        CanonicalMembershipContext;

    readonly current?:
        WorkspaceSummary;
}

export interface CapabilityMembershipRuntime {
    getState():
        CapabilityMembershipSourceState;

    subscribe(
        listener:
            () => void,
    ): () => void;
}

export interface CapabilityWorkspaceRuntime {
    getState():
        CapabilityWorkspaceSourceState;

    subscribe(
        listener:
            () => void,
    ): () => void;
}

export interface CapabilityRuntime {
    getState():
        CapabilityState;

    subscribe(
        listener:
            () => void,
    ): () => void;

    bootstrap(
        options?:
            CapabilityRuntimeOptions,
    ): Promise<
        CapabilityState
    >;

    refresh(
        options?:
            CapabilityRuntimeOptions,
    ): Promise<
        CapabilityState
    >;

    reset():
        CapabilityState;

    dispose():
        void;
}

interface ActiveCapabilityTarget {
    readonly context:
        CanonicalMembershipContext;

    readonly workspace:
        WorkspaceSummary;

    readonly identity:
        string;
}

interface ActiveAbortOperation {
    readonly controller:
        AbortController;

    cleanup():
        void;
}

function canonicalContextsMatch(
    first:
        CanonicalMembershipContext,
    second:
        CanonicalMembershipContext,
): boolean {
    return (
        first.membership.id
            === second.membership.id
        && first.tenant.id
            === second.tenant.id
    );
}

function capabilityTargetIdentity(
    context:
        CanonicalMembershipContext,
    workspace:
        WorkspaceSummary,
): string {
    if (
        workspace.type
            === 'TENANT'
    ) {
        return JSON.stringify([
            context.membership.id,
            context.tenant.id,
            workspace.type,
        ]);
    }

    return JSON.stringify([
        context.membership.id,
        context.tenant.id,
        workspace.type,
        workspace
            .organizational_assignment_id,
        workspace.organization_id,
        workspace.organization_unit_id,
    ]);
}

function resolveCapabilityTarget(
    membership:
        CapabilityMembershipRuntime,
    workspace:
        CapabilityWorkspaceRuntime,
): ActiveCapabilityTarget | undefined {
    const membershipState =
        membership.getState();

    const workspaceState =
        workspace.getState();

    if (
        membershipState.status
            !== 'ready'
        || workspaceState.status
            !== 'ready'
        || membershipState.context
            === undefined
        || membershipState.context
            === null
        || workspaceState.context
            === undefined
        || workspaceState.current
            === undefined
    ) {
        return undefined;
    }

    if (
        ! canonicalContextsMatch(
            membershipState.context,
            workspaceState.context,
        )
    ) {
        return undefined;
    }

    return {
        context:
            membershipState.context,

        workspace:
            workspaceState.current,

        identity:
            capabilityTargetIdentity(
                membershipState.context,
                workspaceState.current,
            ),
    };
}

function createAbortOperation(
    externalSignal?:
        AbortSignal,
): ActiveAbortOperation {
    const controller =
        new AbortController();

    if (
        externalSignal
            === undefined
    ) {
        return {
            controller,

            cleanup() {
                // No external listener.
            },
        };
    }

    const abortFromExternal =
        () => {
            controller.abort();
        };

    if (
        externalSignal.aborted
    ) {
        controller.abort();

        return {
            controller,

            cleanup() {
                // No listener was registered.
            },
        };
    }

    externalSignal.addEventListener(
        'abort',
        abortFromExternal,
        {
            once:
                true,
        },
    );

    return {
        controller,

        cleanup() {
            externalSignal
                .removeEventListener(
                    'abort',
                    abortFromExternal,
                );
        },
    };
}

export function createCapabilityRuntime(
    operations:
        CapabilityProjectionOperations,
    membership:
        CapabilityMembershipRuntime,
    workspace:
        CapabilityWorkspaceRuntime,
): CapabilityRuntime {
    let state:
        CapabilityState =
            createInitialCapabilityState();

    let operationRevision =
        0;

    let activeTargetIdentity:
        string | null =
            null;

    let activeAbortOperation:
        ActiveAbortOperation | null =
            null;

    let disposed =
        false;

    const listeners =
        new Set<
            () => void
        >();

    const publish =
        (
            action:
                CapabilityAction,
        ): CapabilityState => {
            state =
                capabilityReducer(
                    state,
                    action,
                );

            for (
                const listener
                of listeners
            ) {
                listener();
            }

            return state;
        };

    const cancelActiveOperation =
        (): void => {
            operationRevision +=
                1;

            if (
                activeAbortOperation
                    === null
            ) {
                return;
            }

            activeAbortOperation
                .cleanup();

            activeAbortOperation
                .controller
                .abort();

            activeAbortOperation =
                null;
        };

    const resetAuthority =
        (): CapabilityState => {
            cancelActiveOperation();

            activeTargetIdentity =
                null;

            if (
                state.status
                    === 'unresolved'
            ) {
                return state;
            }

            return publish({
                type:
                    'RESET',
            });
        };

    const load =
        async (
            options:
                CapabilityRuntimeOptions = {},
            force =
                false,
        ): Promise<
            CapabilityState
        > => {
            if (
                disposed
            ) {
                return state;
            }

            const target =
                resolveCapabilityTarget(
                    membership,
                    workspace,
                );

            if (
                target
                    === undefined
            ) {
                return resetAuthority();
            }

            if (
                ! force
                && activeTargetIdentity
                    === target.identity
                && (
                    state.status
                        === 'loading'
                    || state.status
                        === 'ready'
                    || state.status
                        === 'unavailable'
                )
            ) {
                return state;
            }

            cancelActiveOperation();

            const revision =
                operationRevision;

            const abortOperation =
                createAbortOperation(
                    options.signal,
                );

            activeAbortOperation =
                abortOperation;

            activeTargetIdentity =
                target.identity;

            publish({
                type:
                    'LOAD_STARTED',
            });

            const result =
                target.workspace.type
                    === 'TENANT'
                    ? await operations
                        .projectTenant(
                            target.context
                                .membership.id,
                            {
                                signal:
                                    abortOperation
                                        .controller
                                        .signal,
                            },
                        )
                    : await operations
                        .projectWorkspace(
                            target.context
                                .membership.id,
                            target.workspace
                                .organizational_assignment_id,
                            {
                                signal:
                                    abortOperation
                                        .controller
                                        .signal,
                            },
                        );

            abortOperation
                .cleanup();

            if (
                activeAbortOperation
                    === abortOperation
            ) {
                activeAbortOperation =
                    null;
            }

            if (
                disposed
                || revision
                    !== operationRevision
            ) {
                return state;
            }

            if (
                ! result.ok
            ) {
                if (
                    result.kind
                        === 'aborted'
                ) {
                    activeTargetIdentity =
                        null;

                    return publish({
                        type:
                            'RESET',
                    });
                }

                return publish({
                    type:
                        'LOAD_FAILED',

                    failure:
                        result,
                });
            }

            const validation =
                target.workspace.type
                    === 'TENANT'
                    ? validateTenantCapabilityProjection(
                        target.context,
                        result.data,
                    )
                    : validateWorkspaceCapabilityProjection(
                        target.context,
                        createWorkspaceCapabilityScopeExpectation(
                            target.workspace,
                        ),
                        result.data,
                    );

            if (
                ! validation.ok
            ) {
                return publish({
                    type:
                        'LOAD_FAILED',

                    failure:
                        validation,
                });
            }

            return publish({
                type:
                    'PROJECTION_ACCEPTED',

                projection:
                    validation.data,
            });
        };

    const synchronize =
        (): void => {
            void load();
        };

    const unsubscribeMembership =
        membership.subscribe(
            synchronize,
        );

    const unsubscribeWorkspace =
        workspace.subscribe(
            synchronize,
        );

    return {
        getState() {
            return state;
        },

        subscribe(
            listener,
        ) {
            listeners.add(
                listener,
            );

            return () => {
                listeners.delete(
                    listener,
                );
            };
        },

        bootstrap(
            options = {},
        ) {
            return load(
                options,
            );
        },

        refresh(
            options = {},
        ) {
            return load(
                options,
                true,
            );
        },

        reset() {
            return resetAuthority();
        },

        dispose() {
            if (
                disposed
            ) {
                return;
            }

            disposed =
                true;

            unsubscribeMembership();
            unsubscribeWorkspace();

            resetAuthority();

            listeners.clear();
        },
    };
}
