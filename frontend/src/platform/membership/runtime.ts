
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
    createInitialMembershipContextState,
    membershipContextReducer,
    type MembershipContextAction,
    type MembershipContextState,
} from '@/platform/membership/state';

export interface MembershipContextRuntimeOptions {
    readonly signal?:
        AbortSignal;
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
            === 'membership-context-required'
    ) {
        return null;
    }

    /*
     * undefined means the authentication runtime is
     * currently not eligible for Membership discovery:
     *
     * unknown
     * anonymous
     * authenticating
     * resolving-context
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

    const reset = () => {
        /*
         * Invalidate every outstanding asynchronous
         * operation before resetting local Membership
         * truth.
         */
        operationRevision +=
            1;

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

            return dispatch({
                type:
                    'DISCOVERY_READY',
                memberships:
                    discoveredMemberships,
            });
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
                    return dispatch({
                        type:
                            'CONTEXT_CONFIRMED',
                        context:
                            confirmedContext,
                    });
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
