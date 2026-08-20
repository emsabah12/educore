import {
    createContext,
    type PropsWithChildren,
    useContext,
    useEffect,
    useSyncExternalStore,
} from 'react';

import type {
    BrowserAuthRuntime,
    BrowserAuthState,
} from '@/platform/auth';

const BrowserAuthRuntimeContext =
    createContext<
        BrowserAuthRuntime | null
    >(null);

export interface BrowserAuthProviderProps
    extends PropsWithChildren {
    readonly runtime:
        BrowserAuthRuntime;
}

export function BrowserAuthProvider({
    runtime,
    children,
}: BrowserAuthProviderProps) {
    useEffect(
        () => {
            /*
             * Runtime construction is intentionally
             * side-effect free.
             *
             * Authentication truth is resolved only
             * after the React application mounts.
             */
            if (
                runtime.getState().status
                    !== 'unknown'
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
        },
        [
            runtime,
        ],
    );

    return (
        <BrowserAuthRuntimeContext.Provider
            value={runtime}
        >
            {children}
        </BrowserAuthRuntimeContext.Provider>
    );
}

export function useBrowserAuthRuntime():
    BrowserAuthRuntime {
    const runtime =
        useContext(
            BrowserAuthRuntimeContext,
        );

    if (runtime === null) {
        throw new Error(
            'EduCore BrowserAuth hooks require BrowserAuthProvider.',
        );
    }

    return runtime;
}

export function useBrowserAuthState():
    BrowserAuthState {
    const runtime =
        useBrowserAuthRuntime();

    return useSyncExternalStore(
        runtime.subscribe,
        runtime.getState,
    );
}
