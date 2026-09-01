import {
    createContext,
    type PropsWithChildren,
    useContext,
    useEffect,
    useRef,
    useSyncExternalStore,
} from 'react';

import {
    useBrowserAuthState,
} from '@/app/auth/BrowserAuthProvider';
import type {
    MembershipContextRuntime,
    MembershipContextState,
} from '@/platform/membership';

const MembershipRuntimeContext =
    createContext<
        MembershipContextRuntime | null
    >(null);

export interface MembershipContextProviderProps
    extends PropsWithChildren {
    readonly runtime:
        MembershipContextRuntime;
}

export function MembershipContextProvider({
    runtime,
    children,
}: MembershipContextProviderProps) {
    const authenticationState =
        useBrowserAuthState();

    /*
     * The controller belongs to the Provider lifecycle,
     * not to one authentication-status Effect execution.
     *
     * Membership discovery or canonical target
     * verification may legitimately change BrowserAuth
     * state while the Membership operation is still in
     * flight. React must not interpret that state change as
     * automatic cancellation of the operation that caused
     * it.
     */
    const activeBootstrapControllerRef =
        useRef<
            AbortController | null
        >(null);

    /*
     * Runtime replacement / Provider unmount is a genuine
     * lifecycle boundary.
     *
     * StrictMode cleanup also reaches this path, so the
     * next Effect replay may safely replace an aborted
     * discovery through the Membership runtime's existing
     * revision semantics.
     */
    useEffect(
        () => {
            return () => {
                activeBootstrapControllerRef
                    .current
                    ?.abort();

                activeBootstrapControllerRef
                    .current =
                        null;
            };
        },
        [
            runtime,
        ],
    );

    useEffect(
        () => {
            const authenticationStatus =
                authenticationState.status;

            if (
                authenticationStatus
                    === 'identity-authenticated'
                || authenticationStatus
                    === 'authenticated'
                || authenticationStatus
                    === 'membership-context-required'
            ) {
                const membershipStatus =
                    runtime.getState().status;

                /*
                 * StrictMode may replay while discovery is
                 * already in flight.
                 *
                 * The runtime supports replacing an
                 * in-flight discovery, but it must never
                 * start another bootstrap while Membership
                 * is switching or already authoritative.
                 */
                if (
                    membershipStatus
                        !== 'unresolved'
                    && membershipStatus
                        !== 'discovering'
                ) {
                    return;
                }

                /*
                 * An existing live Provider-owned operation
                 * already owns this lifecycle.
                 */
                if (
                    activeBootstrapControllerRef
                        .current
                        !== null
                    && ! activeBootstrapControllerRef
                        .current
                        .signal
                        .aborted
                ) {
                    return;
                }

                const controller =
                    new AbortController();

                activeBootstrapControllerRef
                    .current =
                        controller;

                void runtime.bootstrap({
                    signal:
                        controller.signal,

                    /*
                     * Fresh global authentication must
                     * never silently reuse a previous
                     * tab-local Membership selection.
                     *
                     * Only an existing BrowserSession that
                     * reports missing Membership context may
                     * use the advisory restoration hint.
                     */
                    restoreHint:
                        authenticationStatus
                            === 'membership-context-required',
                }).finally(
                    () => {
                        if (
                            activeBootstrapControllerRef
                                .current
                                === controller
                        ) {
                            activeBootstrapControllerRef
                                .current =
                                    null;
                        }
                    },
                );

                /*
                 * Deliberately no Effect cleanup here.
                 *
                 * Canonical Membership verification may
                 * transition authentication into the fully
                 * authenticated state as part of this same
                 * Membership lifecycle. Cancelling on every
                 * authentication dependency transition
                 * would make the operation abort itself.
                 */
                return;
            }

            /*
             * All other Auth states invalidate Membership
             * authority.
             *
             * Cancel any provider-owned bootstrap before
             * resetting local context.
             */
            activeBootstrapControllerRef
                .current
                ?.abort();

            activeBootstrapControllerRef
                .current =
                    null;

            if (
                runtime.getState().status
                    !== 'unresolved'
            ) {
                runtime.reset();
            }
        },
        [
            authenticationState.status,
            runtime,
        ],
    );

    return (
        <MembershipRuntimeContext.Provider
            value={runtime}
        >
            {children}
        </MembershipRuntimeContext.Provider>
    );
}

export function useMembershipContextRuntime():
    MembershipContextRuntime {
    const runtime =
        useContext(
            MembershipRuntimeContext,
        );

    if (
        runtime
            === null
    ) {
        throw new Error(
            'EduCore MembershipContext hooks require MembershipContextProvider.',
        );
    }

    return runtime;
}

export function useMembershipContextState():
    MembershipContextState {
    const runtime =
        useMembershipContextRuntime();

    return useSyncExternalStore(
        runtime.subscribe,
        runtime.getState,
    );
}
