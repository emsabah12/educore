
import type {
    BrowserApiProtocolFailure,
} from '@/platform/api';
import {
    isBrowserSessionAuthenticationRequiredFailure,
    type BrowserAuthRuntime,
    type BrowserAuthState,
} from '@/platform/auth';

import type {
    BrowserMembershipSwitchTarget,
    CanonicalMembershipContext,
} from '@/platform/membership/contract';
import type {
    MembershipContextOperations,
} from '@/platform/membership/operations';
import { createMembershipRequestAbortOptions } from '@/platform/membership/request-options';
import {
    clearBrowserMembershipRestorationHint,
    persistBrowserMembershipRestorationHint,
    readBrowserMembershipRestorationHint,
} from '@/platform/membership/restoration';
import {
    createInitialMembershipContextState,
    membershipContextReducer,
    type MembershipContextAction,
    type MembershipContextState,
} from '@/platform/membership/state';

export interface MembershipContextRuntimeOptions {
    readonly signal?:
        AbortSignal;

    /*
     * A restoration hint may only be considered during an
     * initial BrowserSession context-resolution lifecycle.
     *
     * The hint remains client-owned convenience state and
     * never becomes canonical Membership authority.
     */
    readonly restoreHint?:
        boolean;
}

export type MembershipAuthenticationRuntime =
    Pick<
        BrowserAuthRuntime,
        | 'getState'
        | 'bootstrap'
        | 'observeFailure'
    >;

export interface MembershipContextRuntime {
    getState():
        MembershipContextState;

    subscribe(
        listener: () => void,
    ): () => void;

    bootstrap(
        options?:
            MembershipContextRuntimeOptions,
    ): Promise<
        MembershipContextState
    >;

    switchMembership(
        membershipId:
            BrowserMembershipSwitchTarget,
        options?:
            MembershipContextRuntimeOptions,
    ): Promise<
        MembershipContextState
    >;

    reset():
        MembershipContextState;
}

function canonicalContextFromAuthentication(
    state: BrowserAuthState,
):
    | CanonicalMembershipContext
    | null
    | undefined {
    if (
        state.status
            === 'authenticated'
    ) {
        return {
            membership:
                state.identity.membership,

            tenant:
                state.identity.tenant,
        };
    }

    if (
        state.status
            === 'identity-authenticated'
        || state.status
            === 'membership-context-required'
    ) {
        /*
         * The User identity is canonical, but no
         * Membership/Tenant context is currently active.
         *
         * null deliberately means Membership discovery is
         * allowed and must start without claiming current
         * Tenant authority.
         */
        return null;
    }

    /*
     * undefined means the authentication runtime is
     * currently not eligible for Membership discovery:
     *
     * unknown
     * anonymous
     * authenticating
     * logging-out
     * unavailable
     */
    return undefined;
}

function isTransientState(
    state: MembershipContextState,
): boolean {
    return (
        state.status
            === 'discovering'
        || state.status
            === 'switching'
    );
}

export function createMembershipContextRuntime(
    operations:
        MembershipContextOperations,
    authentication:
        MembershipAuthenticationRuntime,
): MembershipContextRuntime {
    let state:
        MembershipContextState =
            createInitialMembershipContextState();

    /*
     * Stable state is deliberately kept separately from
     * transient discovering/switching state.
     *
     * Cancellation therefore restores the last meaningful
     * state instead of leaving the runtime stuck in an
     * in-flight state.
     */
    let stableState:
        MembershipContextState =
            state;

    let operationRevision =
        0;

    const listeners =
        new Set<
            () => void
        >();

    const getState = () =>
        state;

    const subscribe = (
        listener: () => void,
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
        for (
            const listener
            of listeners
        ) {
            listener();
        }
    };

    const replaceState = (
        next:
            MembershipContextState,
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
            MembershipContextAction,
    ) =>
        replaceState(
            membershipContextReducer(
                state,
                action,
            ),
        );

    const clearRestorationHint =
        () => {
            /*
             * Membership restoration is UX convenience,
             * never authentication or authorization
             * authority.
             */
            clearBrowserMembershipRestorationHint();
        };

    const persistCurrentContext =
        (
            context:
                CanonicalMembershipContext,
        ) => {
            persistBrowserMembershipRestorationHint(
                context,
            );
        };

    const reset = () => {
        /*
         * Invalidate every outstanding asynchronous
         * operation before resetting local Membership
         * truth.
         */
        operationRevision +=
            1;

        /*
         * A reset means the previous Membership context
         * must no longer be proposed during a later reload.
         */
        clearRestorationHint();

        return dispatch({
            type:
                'RESET',
        });
    };

    const bootstrap = async (
        options:
            MembershipContextRuntimeOptions = {},
    ): Promise<
        MembershipContextState
    > => {
        /*
         * Discovery must never interrupt credential
         * preparation + canonical context confirmation.
         */
        if (
            state.status
                === 'switching'
        ) {
            return state;
        }

        const authenticationState =
            authentication.getState();

        const context =
            canonicalContextFromAuthentication(
                authenticationState,
            );

        if (
            context
                === undefined
        ) {
            return reset();
        }

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
                createMembershipRequestAbortOptions(
                    options.signal,
                ),
            );

        /*
         * A newer operation owns Membership truth now.
         * A late result is intentionally ignored.
         */
        if (
            revision
                !== operationRevision
        ) {
            return state;
        }

        if (
            ! result.ok
        ) {
            /*
             * Cancellation is caller lifecycle, not
             * Membership truth.
             */
            if (
                result.kind
                    === 'aborted'
            ) {
                return replaceState(
                    rollbackState,
                );
            }

            if (
                isBrowserSessionAuthenticationRequiredFailure(
                    result,
                )
            ) {
                authentication.observeFailure(
                    result,
                );

                return reset();
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
        ) {
            /*
             * A successful HTTP status without the
             * contract-required MembershipListSuccess
             * payload is a protocol failure.
             *
             * Never invent an empty Membership list and
             * never bypass the transport's optional-data
             * contract with a type assertion.
             */
            const failure:
                BrowserApiProtocolFailure = {
                    ok: false,
                    kind:
                        'protocol',
                    status:
                        result.status,
                    message:
                        'EduCore API returned an unexpected error response.',
                };

            return dispatch({
                type:
                    'DISCOVERY_UNAVAILABLE',
                failure,
            });
        }

        try {
            const discoveredMemberships =
                result.data.data;

            if (
                discoveredMemberships.length
                    === 0
            ) {
                return dispatch({
                    type:
                        'DISCOVERY_EMPTY',
                });
            }

            const discoveredState =
                dispatch({
                    type:
                        'DISCOVERY_READY',
                    memberships:
                        discoveredMemberships,
                });

            /*
             * Normal authenticated bootstrap already owns a
             * canonical Membership/Tenant context.
             *
             * Persist it only after discovery has validated
             * that the pair is still available.
             */
            if (
                discoveredState.status
                    === 'ready'
            ) {
                persistCurrentContext(
                    discoveredState.context,
                );

                return discoveredState;
            }

            /*
             * Fresh global authentication with exactly one
             * available Membership may auto-select it.
             *
             * Auto-selection is orchestration convenience
             * only. Discovery never becomes Tenant
             * authority by itself.
             *
             * Reuse the same canonical switch path as an
             * explicit user selection:
             *
             * discover
             * -> SWITCH_STARTED
             * -> Browser Membership switch
             * -> /auth/me verification
             * -> CONTEXT_CONFIRMED
             *
             * A BrowserSession restoration lifecycle is
             * intentionally excluded here. Reload owns its
             * separate advisory-hint verification path.
             */
            if (
                options.restoreHint
                    !== true
                && discoveredState.status
                    === 'selection-required'
                && discoveredMemberships.length
                    === 1
            ) {
                const onlyMembership =
                    discoveredMemberships[0];

                /*
                 * Length === 1 proves this at runtime, but
                 * keep the defensive guard for strict array
                 * indexing configurations and malformed
                 * contract data.
                 */
                if (
                    onlyMembership
                        === undefined
                ) {
                    throw new Error(
                        'EduCore MembershipContext single-Membership discovery did not contain a switch target.',
                    );
                }

                return await switchMembership(
                    onlyMembership
                        .membership_id,

                    createMembershipRequestAbortOptions(
                        options.signal,
                    ),
                );
            }

            /*
             * Fresh authentication with multiple
             * Memberships must preserve explicit selection.
             *
             * Restoration is enabled only when the caller
             * explicitly classifies this bootstrap as an
             * existing BrowserSession reload lifecycle.
             */
            if (
                options.restoreHint
                    !== true
                || discoveredState.status
                    !== 'selection-required'
            ) {
                return discoveredState;
            }

            const restorationHint =
                readBrowserMembershipRestorationHint();

            if (
                restorationHint
                    === null
            ) {
                return discoveredState;
            }

            /*
             * Client-owned storage cannot choose an
             * arbitrary Membership.
             *
             * Discovery must first prove that the stored
             * Membership/Tenant pair is still available to
             * the authenticated Person.
             */
            const restorationTarget =
                discoveredMemberships.find(
                    (membership) =>
                        membership.membership_id
                            === restorationHint
                                .membership_id
                        && membership.tenant_id
                            === restorationHint
                                .tenant_id,
                );

            if (
                restorationTarget
                    === undefined
            ) {
                clearRestorationHint();

                return discoveredState;
            }

            /*
             * Reuse the existing reducer's guarded target
             * transition.
             *
             * No browser membership-switch mutation is sent
             * during reload: BrowserSession already owns the
             * prepared credential from the prior canonical
             * login/switch lifecycle.
             */
            dispatch({
                type:
                    'SWITCH_STARTED',
                membershipId:
                    restorationTarget
                        .membership_id,
            });

            const confirmedAuthentication =
                await authentication.bootstrap({
                    membershipId:
                        restorationTarget
                            .membership_id,

                    ...createMembershipRequestAbortOptions(
                        options.signal,
                    ),
                });

            if (
                revision
                    !== operationRevision
            ) {
                return state;
            }

            /*
             * An aborted React lifecycle is not evidence
             * that the stored hint became invalid.
             */
            if (
                options.signal
                    ?.aborted
                === true
            ) {
                return replaceState(
                    discoveredState,
                );
            }

            if (
                confirmedAuthentication.status
                    !== 'authenticated'
            ) {
                clearRestorationHint();

                return replaceState(
                    discoveredState,
                );
            }

            const confirmedContext:
                CanonicalMembershipContext = {
                    membership:
                        confirmedAuthentication
                            .identity
                            .membership,

                    tenant:
                        confirmedAuthentication
                            .identity
                            .tenant,
                };

            /*
             * The browser hint and discovery result are
             * still not authority.
             *
             * Canonical /auth/me must confirm exactly the
             * same Membership/Tenant pair before local
             * Membership state may become READY.
             */
            if (
                confirmedContext
                    .membership
                    .id
                    !== restorationTarget
                        .membership_id
                || confirmedContext
                    .tenant
                    .id
                    !== restorationTarget
                        .tenant_id
            ) {
                clearRestorationHint();

                return replaceState(
                    discoveredState,
                );
            }

            const restoredState =
                dispatch({
                    type:
                        'CONTEXT_CONFIRMED',
                    context:
                        confirmedContext,
                });

            if (
                restoredState.status
                    === 'ready'
            ) {
                persistCurrentContext(
                    restoredState.context,
                );
            }

            return restoredState;
        } catch (error) {
            /*
             * Discovery contradictions are invariant
             * failures. Restore the last stable state
             * before surfacing the programming/server
             * contract error.
             */
            replaceState(
                rollbackState,
            );

            throw error;
        }
    };

    const switchMembership =
        async (
            membershipId:
                BrowserMembershipSwitchTarget,
            options:
                MembershipContextRuntimeOptions = {},
        ): Promise<
            MembershipContextState
        > => {
            const rollbackState =
                stableState;

            const revision =
                ++operationRevision;

            /*
             * The reducer validates both:
             * - current state is switchable
             * - target exists in discovered memberships
             */
            dispatch({
                type:
                    'SWITCH_STARTED',
                membershipId,
            });

            const switchResult =
                await operations.switchMembership(
                    membershipId,
                    createMembershipRequestAbortOptions(
                        options.signal,
                    ),
                );

            if (
                revision
                    !== operationRevision
            ) {
                return state;
            }

            if (
                ! switchResult.ok
            ) {
                if (
                    switchResult.kind
                        === 'aborted'
                ) {
                    return replaceState(
                        rollbackState,
                    );
                }

                if (
                    isBrowserSessionAuthenticationRequiredFailure(
                        switchResult,
                    )
                ) {
                    authentication.observeFailure(
                        switchResult,
                    );

                    return reset();
                }

                return dispatch({
                    type:
                        'SWITCH_FAILED',
                    failure:
                        switchResult,
                });
            }

            /*
             * Browser switch success only proves the target
             * credential was prepared in BrowserSession
             * custody.
             *
             * It is NOT yet current Membership truth.
             */
            const confirmedAuthentication =
                await authentication.bootstrap({
                    membershipId,
                    ...createMembershipRequestAbortOptions(
                        options.signal,
                    ),
                });

            if (
                revision
                    !== operationRevision
            ) {
                return state;
            }

            if (
                confirmedAuthentication.status
                    === 'authenticated'
            ) {
                const confirmedContext:
                    CanonicalMembershipContext = {
                        membership:
                            confirmedAuthentication
                                .identity
                                .membership,

                        tenant:
                            confirmedAuthentication
                                .identity
                                .tenant,
                    };

                try {
                    const confirmedState =
                        dispatch({
                            type:
                                'CONTEXT_CONFIRMED',
                            context:
                                confirmedContext,
                        });

                    if (
                        confirmedState.status
                            === 'ready'
                    ) {
                        persistCurrentContext(
                            confirmedState.context,
                        );
                    }

                    return confirmedState;
                } catch (error) {
                    /*
                     * Authentication confirmed a canonical
                     * Membership/Tenant pair different from
                     * the requested switch target.
                     *
                     * Do not retain either context locally.
                     */
                    reset();

                    throw error;
                }
            }

            /*
             * BrowserAuthRuntime treats an aborted bootstrap
             * as authentication-state-neutral. If target
             * confirmation was cancelled, preserve the
             * previous stable Membership context too.
             */
            if (
                options.signal
                    ?.aborted
                === true
            ) {
                return replaceState(
                    rollbackState,
                );
            }

            /*
             * Canonical authentication confirmation failed
             * or became unavailable.
             *
             * AuthenticationRuntime already owns the exact
             * reason. MembershipRuntime must not claim the
             * previous or target Membership as current when
             * canonical auth truth cannot confirm it.
             */
            return reset();
        };

    return {
        getState,
        subscribe,
        bootstrap,
        switchMembership,
        reset,
    };
}
