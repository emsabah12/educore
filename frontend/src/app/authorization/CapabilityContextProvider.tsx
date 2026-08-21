import {
    createContext,
    type PropsWithChildren,
    useContext,
    useSyncExternalStore,
} from 'react';

import type {
    CapabilityRuntime,
    CapabilityState,
} from '@/platform/authorization';

const CapabilityRuntimeContext =
    createContext<
        CapabilityRuntime | null
    >(null);

export interface CapabilityContextProviderProps
    extends PropsWithChildren {
    readonly runtime:
        CapabilityRuntime;
}

/*
 * CapabilityRuntime already owns lifecycle orchestration
 * through its Membership and Workspace subscriptions.
 *
 * This Provider is deliberately a read bridge only.
 * It must not bootstrap, refresh, reset, or otherwise
 * manufacture authorization authority from React.
 */
export function CapabilityContextProvider({
    runtime,
    children,
}: CapabilityContextProviderProps) {
    return (
        <CapabilityRuntimeContext.Provider
            value={runtime}
        >
            {children}
        </CapabilityRuntimeContext.Provider>
    );
}

export function useCapabilityRuntime():
    CapabilityRuntime {
    const runtime =
        useContext(
            CapabilityRuntimeContext,
        );

    if (
        runtime
            === null
    ) {
        throw new Error(
            'EduCore Capability hooks require CapabilityContextProvider.',
        );
    }

    return runtime;
}

export function useCapabilityState():
    CapabilityState {
    const runtime =
        useCapabilityRuntime();

    return useSyncExternalStore(
        runtime.subscribe,
        runtime.getState,
    );
}
