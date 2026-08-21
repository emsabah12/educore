import type {
    BrowserApiFailure,
    BrowserApiProtocolFailure,
} from '@/platform/api';
import type {
    CanonicalMembershipContext,
    MembershipContextRuntime,
    MembershipContextState,
} from '@/platform/membership';

import type {
    OrganizationUnitWorkspaceSummary,
    OrganizationWorkspaceSummary,
    TenantWorkspaceSummary,
    WorkspaceDiscoveryData,
    WorkspaceDiscoverySuccess,
    WorkspaceDiscoveryTenant,
    WorkspaceSummary,
} from '@/platform/workspace/contract';
import type {
    WorkspaceContextOperations,
} from '@/platform/workspace/operations';
import {
    clearBrowserWorkspaceRestorationHint,
    persistBrowserWorkspaceRestorationHint,
    readBrowserWorkspaceRestorationHint,
    resolveWorkspaceRestorationTarget,
} from '@/platform/workspace/restoration';
import {
    createInitialWorkspaceContextState,
    validateWorkspaceDiscovery,
    workspaceContextReducer,
    type WorkspaceContextAction,
    type WorkspaceContextState,
} from '@/platform/workspace/state';
import type {
    WorkspaceContextVerifier,
    WorkspaceVerificationOptions,
    WorkspaceVerificationResult,
} from '@/platform/workspace/verification';

export interface WorkspaceContextRuntimeOptions {
    readonly signal?:
        AbortSignal;
}

export interface WorkspaceContextBootstrapOptions
    extends WorkspaceContextRuntimeOptions {
    /*
     * Reload bootstrap may validate and restore the
     * advisory per-tab Workspace hint.
     *
     * Fresh authentication or Membership/Tenant context
     * must set this to false so stale organizational
     * context is discarded and TENANT remains the safe
     * baseline.
     *
     * Default true preserves the established reload
     * behavior for existing callers.
     */
    readonly restoreHint?:
        boolean;
}

export type WorkspaceMembershipRuntime =
    Pick<
        MembershipContextRuntime,
        | 'getState'
        | 'subscribe'
    >;

export interface WorkspaceContextRuntime {
    getState():
        WorkspaceContextState;

    subscribe(
        listener:
            () => void,
    ): () => void;

    bootstrap(
        options?:
            WorkspaceContextBootstrapOptions,
    ): Promise<
        WorkspaceContextState
    >;

    switchWorkspace(
        target:
            WorkspaceSummary,
        options?:
            WorkspaceContextRuntimeOptions,
    ): Promise<
        WorkspaceContextState
    >;

    recoverStaleWorkspace(
        failure:
            BrowserApiFailure,
        options?:
            WorkspaceContextRuntimeOptions,
    ): Promise<
        WorkspaceContextState
    >;

    reset():
        WorkspaceContextState;

    dispose():
        void;
}

function canonicalContextFromMembership(
    state:
        MembershipContextState,
):
    | CanonicalMembershipContext
    | undefined {
    if (
        state.status
            !== 'ready'
    ) {
        return undefined;
    }

    return state.context;
}

function contextFromWorkspaceState(
    state:
        WorkspaceContextState,
):
    | CanonicalMembershipContext
    | undefined {
    if (
        state.status
            === 'unresolved'
    ) {
        return undefined;
    }

    return state.context;
}

function contextsMatch(
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

function isTransientState(
    state:
        WorkspaceContextState,
): boolean {
    return (
        state.status
            === 'discovering'
        || state.status
            === 'switching'
    );
}

function createAbortOptions(
    signal:
        AbortSignal
        | undefined,
): WorkspaceVerificationOptions {
    if (
        signal === undefined
    ) {
        return {};
    }

    return {
        signal,
    };
}

function isRecord(
    value: unknown,
): value is Record<
    string,
    unknown
> {
    return (
        typeof value
            === 'object'
        && value !== null
        && ! Array.isArray(
            value,
        )
    );
}

function isString(
    value: unknown,
): value is string {
    return (
        typeof value
            === 'string'
    );
}

function isTenantWorkspaceSummary(
    value: unknown,
): value is TenantWorkspaceSummary {
    if (
        ! isRecord(
            value,
        )
    ) {
        return false;
    }

    return (
        value.type
            === 'TENANT'
        && value
            .organizational_assignment_id
            === null
        && value.organization_id
            === null
        && value.organization_unit_id
            === null
        && isString(
            value.label,
        )
    );
}

function isOrganizationWorkspaceSummary(
    value: unknown,
): value is OrganizationWorkspaceSummary {
    if (
        ! isRecord(
            value,
        )
    ) {
        return false;
    }

    return (
        value.type
            === 'ORGANIZATION'
        && isString(
            value
                .organizational_assignment_id,
        )
        && isString(
            value.organization_id,
        )
        && value.organization_unit_id
            === null
        && isString(
            value.label,
        )
    );
}

function isOrganizationUnitWorkspaceSummary(
    value: unknown,
): value is OrganizationUnitWorkspaceSummary {
    if (
        ! isRecord(
            value,
        )
    ) {
        return false;
    }

    return (
        value.type
            === 'ORGANIZATION_UNIT'
        && isString(
            value
                .organizational_assignment_id,
        )
        && isString(
            value.organization_id,
        )
        && isString(
            value
                .organization_unit_id,
        )
        && isString(
            value.label,
        )
    );
}

function isWorkspaceSummary(
    value: unknown,
): value is WorkspaceSummary {
    return (
        isTenantWorkspaceSummary(
            value,
        )
        || isOrganizationWorkspaceSummary(
            value,
        )
        || isOrganizationUnitWorkspaceSummary(
            value,
        )
    );
}

function isWorkspaceDiscoveryTenant(
    value: unknown,
): value is WorkspaceDiscoveryTenant {
    if (
        ! isRecord(
            value,
        )
    ) {
        return false;
    }

    return (
        isString(
            value.id,
        )
        && isString(
            value.name,
        )
    );
}

function isWorkspaceDiscoveryData(
    value: unknown,
): value is WorkspaceDiscoveryData {
    if (
        ! isRecord(
            value,
        )
    ) {
        return false;
    }

    return (
        isWorkspaceDiscoveryTenant(
            value.tenant,
        )
        && Array.isArray(
            value.workspaces,
        )
        && value.workspaces.every(
            isWorkspaceSummary,
        )
    );
}

function isWorkspaceDiscoverySuccess(
    value: unknown,
): value is WorkspaceDiscoverySuccess {
    if (
        ! isRecord(
            value,
        )
    ) {
        return false;
    }

    return (
        value.status
            === 'success'
        && isWorkspaceDiscoveryData(
            value.data,
        )
    );
}

function createProtocolFailure(
    status:
        number,
): BrowserApiProtocolFailure {
    return {
        ok:
            false,
        kind:
            'protocol',
        status,
        message:
            'EduCore API returned an unexpected error response.',
    };
}

export function isOrganizationalContextDeniedFailure(
    failure:
        BrowserApiFailure,
): boolean {
    return (
        failure.kind
            === 'response'
        && failure.status
            === 403
        && failure.error.code
            === 'ORGANIZATIONAL_CONTEXT_DENIED'
    );
}

export function createWorkspaceContextRuntime(
    operations:
        WorkspaceContextOperations,
    membership:
        WorkspaceMembershipRuntime,
    verifier:
        WorkspaceContextVerifier,
): WorkspaceContextRuntime {
    let state:
        WorkspaceContextState =
            createInitialWorkspaceContextState();

    /*
     * Stable state is kept independently from transient
     * discovery/switch states.
     *
     * Cancellation can therefore restore meaningful
     * Workspace truth without claiming an in-flight target
     * became authoritative.
     */
    let stableState:
        WorkspaceContextState =
            state;

    let operationRevision =
        0;

    let disposed =
        false;

    const listeners =
        new Set<
            () => void
        >();

    const getState = () =>
        state;

    const subscribe = (
        listener:
            () => void,
    ) => {
        listeners.add(
            listener,
        );

        return () => {
            listeners.delete(
                listener,
            );
        };
    };

    const notify = () => {
        if (
            disposed
        ) {
            return;
        }

        for (
            const listener
            of listeners
        ) {
            listener();
        }
    };

    const replaceState = (
        next:
            WorkspaceContextState,
    ) => {
        if (
            Object.is(
                state,
                next,
            )
        ) {
            return state;
        }

        state =
            next;

        if (
            ! isTransientState(
                next,
            )
        ) {
            stableState =
                next;
        }

        notify();

        return state;
    };

    const dispatch = (
        action:
            WorkspaceContextAction,
    ) =>
        replaceState(
            workspaceContextReducer(
                state,
                action,
            ),
        );

    const clearRestorationHint =
        () => {
            /*
             * Restoration is convenience state, never
             * Workspace authority.
             *
             * Storage failures therefore must not preserve
             * stale Workspace authority or invalidate
             * canonical Membership/Tenant truth.
             */
            clearBrowserWorkspaceRestorationHint();
        };

    const persistCurrentWorkspace =
        (
            context:
                CanonicalMembershipContext,
            workspace:
                WorkspaceSummary,
        ) => {
            persistBrowserWorkspaceRestorationHint(
                context,
                workspace,
            );
        };

    const reset = () => {
        operationRevision +=
            1;

        if (
            state.status
                === 'unresolved'
        ) {
            /*
             * Do not erase a reload restoration hint before
             * canonical Membership/Tenant bootstrap has had
             * a chance to validate it.
             */
            return state;
        }

        clearRestorationHint();

        return dispatch({
            type:
                'RESET',
        });
    };

    const currentMembershipContext =
        () =>
            canonicalContextFromMembership(
                membership.getState(),
            );

    const membershipStillOwnsContext =
        (
            context:
                CanonicalMembershipContext,
        ) => {
            const current =
                currentMembershipContext();

            return (
                current !== undefined
                && contextsMatch(
                    current,
                    context,
                )
            );
        };

    const verifyWorkspace =
        async (
            context:
                CanonicalMembershipContext,
            workspace:
                WorkspaceSummary,
            options:
                WorkspaceContextRuntimeOptions,
        ): Promise<
            WorkspaceVerificationResult
        > =>
            verifier.verify(
                context,
                workspace,
                createAbortOptions(
                    options.signal,
                ),
            );

    const runDiscovery =
        async (
            context:
                CanonicalMembershipContext,
            options:
                WorkspaceContextRuntimeOptions,
            restoreHint:
                boolean,
        ): Promise<
            WorkspaceContextState
        > => {
            const rollbackState =
                stableState;

            const revision =
                ++operationRevision;

            dispatch({
                type:
                    'DISCOVERY_STARTED',
                context,
            });

            const result =
                await operations.discover(
                    context.membership.id,
                    createAbortOptions(
                        options.signal,
                    ),
                );

            /*
             * A newer Workspace operation or a Membership
             * transition owns truth now.
             */
            if (
                revision
                    !== operationRevision
            ) {
                return state;
            }

            if (
                ! membershipStillOwnsContext(
                    context,
                )
            ) {
                return reset();
            }

            if (
                ! result.ok
            ) {
                if (
                    result.kind
                        === 'aborted'
                ) {
                    return replaceState(
                        rollbackState,
                    );
                }

                return dispatch({
                    type:
                        'DISCOVERY_UNAVAILABLE',
                    failure:
                        result,
                });
            }

            if (
                result.data
                    === undefined
                || ! isWorkspaceDiscoverySuccess(
                    result.data,
                )
            ) {
                return dispatch({
                    type:
                        'DISCOVERY_UNAVAILABLE',
                    failure:
                        createProtocolFailure(
                            result.status,
                        ),
                });
            }

            const discovery =
                result.data.data;

            let tenantWorkspace:
                WorkspaceSummary;

            try {
                /*
                 * One shared invariant validator is used by
                 * both runtime and reducer.
                 *
                 * Runtime needs the canonical TENANT
                 * candidate before it may publish READY.
                 */
                tenantWorkspace =
                    validateWorkspaceDiscovery(
                        context,
                        discovery,
                    );
            } catch (error) {
                replaceState(
                    rollbackState,
                );

                throw error;
            }

            let tenantVerification:
                WorkspaceVerificationResult;

            try {
                tenantVerification =
                    await verifyWorkspace(
                        context,
                        tenantWorkspace,
                        options,
                    );
            } catch (error) {
                replaceState(
                    rollbackState,
                );

                throw error;
            }

            if (
                revision
                    !== operationRevision
            ) {
                return state;
            }

            if (
                ! membershipStillOwnsContext(
                    context,
                )
            ) {
                return reset();
            }

            if (
                ! tenantVerification.ok
            ) {
                if (
                    tenantVerification.kind
                        === 'aborted'
                ) {
                    return replaceState(
                        rollbackState,
                    );
                }

                return dispatch({
                    type:
                        'DISCOVERY_UNAVAILABLE',
                    failure:
                        tenantVerification,
                });
            }

            /*
             * TENANT is published only after its verifier
             * confirms the safe baseline.
             */
            dispatch({
                type:
                    'DISCOVERY_READY',
                discovery,
            });

            if (
                ! restoreHint
            ) {
                return state;
            }

            const restoration =
                readBrowserWorkspaceRestorationHint();

            if (
                ! restoration.ok
            ) {
                /*
                 * Invalid hints are already discarded by the
                 * storage boundary.
                 *
                 * Storage unavailability is non-authoritative
                 * convenience failure: remain safely at
                 * verified TENANT.
                 */
                return state;
            }

            if (
                restoration.hint
                    === null
            ) {
                return state;
            }

            const target =
                resolveWorkspaceRestorationTarget(
                    context,
                    discovery.workspaces,
                    restoration.hint,
                );

            if (
                target === null
            ) {
                clearRestorationHint();

                return state;
            }

            const tenantReadyState =
                state;

            dispatch({
                type:
                    'SWITCH_STARTED',
                target,
            });

            if (
                state.status
                    !== 'switching'
            ) {
                return state;
            }

            let targetVerification:
                WorkspaceVerificationResult;

            try {
                targetVerification =
                    await verifyWorkspace(
                        context,
                        state.target,
                        options,
                    );
            } catch (error) {
                clearRestorationHint();

                replaceState(
                    tenantReadyState,
                );

                throw error;
            }

            if (
                revision
                    !== operationRevision
            ) {
                return state;
            }

            if (
                ! membershipStillOwnsContext(
                    context,
                )
            ) {
                return reset();
            }

            if (
                ! targetVerification.ok
            ) {
                clearRestorationHint();

                if (
                    targetVerification.kind
                        === 'aborted'
                ) {
                    return replaceState(
                        tenantReadyState,
                    );
                }

                return dispatch({
                    type:
                        'SWITCH_FAILED',
                    failure:
                        targetVerification,
                });
            }

            const restored =
                dispatch({
                    type:
                        'TARGET_VERIFIED',
                });

            if (
                restored.status
                    === 'ready'
            ) {
                persistCurrentWorkspace(
                    context,
                    restored.current,
                );
            }

            return restored;
        };

    const bootstrap =
    async (
        options:
            WorkspaceContextBootstrapOptions = {},
    ): Promise<
        WorkspaceContextState
    > => {
        /*
         * A concurrent explicit switch owns Workspace
         * transition until verification completes.
         */
        if (
            state.status
                === 'switching'
        ) {
            return state;
        }

        const context =
            currentMembershipContext();

        if (
            context
                === undefined
        ) {
            return reset();
        }

        const restoreHint =
            options.restoreHint
                ?? true;

        if (
            ! restoreHint
        ) {
            /*
             * Fresh authentication / Membership context
             * must establish TENANT as a genuinely fresh
             * baseline.
             *
             * Merely skipping the read would leave an old
             * organizational hint available to a later
             * bootstrap or reload.
             */
            clearRestorationHint();
        }

        return runDiscovery(
            context,
            {
                ...(
                    options.signal
                        === undefined
                        ? {}
                        : {
                            signal:
                                options.signal,
                        }
                ),
            },
            restoreHint,
        );
    };

    const switchWorkspace =
        async (
            target:
                WorkspaceSummary,
            options:
                WorkspaceContextRuntimeOptions = {},
        ): Promise<
            WorkspaceContextState
        > => {
            const context =
                currentMembershipContext();

            if (
                context
                    === undefined
            ) {
                return reset();
            }

            const workspaceContext =
                contextFromWorkspaceState(
                    state,
                );

            if (
                workspaceContext
                    === undefined
                || ! contextsMatch(
                    workspaceContext,
                    context,
                )
            ) {
                return reset();
            }

            const rollbackState =
                stableState;

            const revision =
                ++operationRevision;

            /*
             * Reducer validates:
             * - state is READY
             * - target exists in canonical catalog
             * - caller metadata is never trusted
             *
             * Selecting current Workspace returns the same
             * READY object and therefore requires no
             * verification.
             */
            dispatch({
                type:
                    'SWITCH_STARTED',
                target,
            });

            if (
                state.status
                    !== 'switching'
            ) {
                if (
                    state.status
                        === 'ready'
                ) {
                    persistCurrentWorkspace(
                        context,
                        state.current,
                    );
                }

                return state;
            }

            let verification:
                WorkspaceVerificationResult;

            try {
                verification =
                    await verifyWorkspace(
                        context,
                        state.target,
                        options,
                    );
            } catch (error) {
                replaceState(
                    rollbackState,
                );

                throw error;
            }

            if (
                revision
                    !== operationRevision
            ) {
                return state;
            }

            if (
                ! membershipStillOwnsContext(
                    context,
                )
            ) {
                return reset();
            }

            if (
                ! verification.ok
            ) {
                if (
                    verification.kind
                        === 'aborted'
                ) {
                    return replaceState(
                        rollbackState,
                    );
                }

                return dispatch({
                    type:
                        'SWITCH_FAILED',
                    failure:
                        verification,
                });
            }

            const verified =
                dispatch({
                    type:
                        'TARGET_VERIFIED',
                });

            if (
                verified.status
                    === 'ready'
            ) {
                persistCurrentWorkspace(
                    context,
                    verified.current,
                );
            }

            return verified;
        };

    const recoverStaleWorkspace =
        async (
            failure:
                BrowserApiFailure,
            options:
                WorkspaceContextRuntimeOptions = {},
        ): Promise<
            WorkspaceContextState
        > => {
            if (
                ! isOrganizationalContextDeniedFailure(
                    failure,
                )
            ) {
                throw new Error(
                    'EduCore WorkspaceContext recovery requires ORGANIZATIONAL_CONTEXT_DENIED.',
                );
            }

            if (
                state.status
                    !== 'ready'
                || state.current.type
                    === 'TENANT'
            ) {
                throw new Error(
                    'EduCore WorkspaceContext stale recovery requires an active organizational Workspace.',
                );
            }

            const context =
                currentMembershipContext();

            if (
                context
                    === undefined
                || ! contextsMatch(
                    state.context,
                    context,
                )
            ) {
                return reset();
            }

            /*
             * Current organizational Workspace has been
             * denied by backend and must stop being
             * authoritative immediately.
             */
            clearRestorationHint();

            dispatch({
                type:
                    'RECOVERY_STARTED',
                failure,
            });

            /*
             * Stale recovery deliberately disables
             * restoration for this discovery cycle.
             *
             * Even if storage cleanup failed, the stale
             * assignment will not be retried immediately.
             */
            return runDiscovery(
                context,
                options,
                false,
            );
        };

    const synchronizeWithMembership =
        () => {
            const workspaceContext =
                contextFromWorkspaceState(
                    state,
                );

            /*
             * Initial unresolved Workspace must not erase
             * a reload restoration hint while Membership
             * is still bootstrapping.
             */
            if (
                workspaceContext
                    === undefined
            ) {
                return;
            }

            const membershipContext =
                currentMembershipContext();

            if (
                membershipContext
                    === undefined
                || ! contextsMatch(
                    workspaceContext,
                    membershipContext,
                )
            ) {
                /*
                 * Membership/Tenant changes invalidate every
                 * Workspace catalog, current selection, and
                 * restoration hint from the old context.
                 */
                reset();
            }
        };

    const unsubscribeMembership =
        membership.subscribe(
            synchronizeWithMembership,
        );

    const dispose = () => {
        if (
            disposed
        ) {
            return;
        }

        disposed =
            true;

        operationRevision +=
            1;

        unsubscribeMembership();

        listeners.clear();
    };

    return {
        getState,
        subscribe,
        bootstrap,
        switchWorkspace,
        recoverStaleWorkspace,
        reset,
        dispose,
    };
}
