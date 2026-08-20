import {
    createContext,
    type PropsWithChildren,
    useContext,
    useEffect,
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

    useEffect(
        () => {
            const authenticationStatus =
                authenticationState.status;

            if (
                authenticationStatus
                    === 'authenticated'
                || authenticationStatus
                    === 'membership-context-required'
            ) {
                const membershipStatus =
                    runtime.getState().status;

                /*
                 * StrictMode may clean up the first Effect
                 * while discovery is already in flight.
                 *
                 * The Membership runtime explicitly
                 * supports replacing an in-flight
                 * discovery and rejects discovery while
                 * switching.
                 */
                if (
                    membershipStatus
                        !== 'unresolved'
                    && membershipStatus
                        !== 'discovering'
                ) {
                    return;
                }

                const controller =
                    new AbortController();

                void runtime.bootstrap({
                    signal:
                        controller.signal,
                });

                return () => {
                    controller.abort();
                };
            }

            /*
             * resolving-context is intentionally neutral.
             *
             * Membership switching confirms its target by
             * calling BrowserAuthRuntime.bootstrap(), which
             * temporarily moves authentication through
             * resolving-context.
             *
             * Resetting Membership truth here would cancel
             * that canonical confirmation lifecycle.
             */
            if (
                authenticationStatus
                    === 'resolving-context'
            ) {
                return;
            }

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

    if (runtime === null) {
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
