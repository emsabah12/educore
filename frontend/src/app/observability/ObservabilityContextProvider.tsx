import {
    createContext,
    type ReactNode,
    useContext,
} from 'react';

import type {
    ObservabilityPort,
} from '@/platform/observability/port';

interface ObservabilityContextProviderProps {
    readonly children:
        ReactNode;

    readonly observability:
        ObservabilityPort;
}

const ObservabilityContext =
    createContext<
        ObservabilityPort | null
    >(
        null,
    );

export function ObservabilityContextProvider({
    children,
    observability,
}: ObservabilityContextProviderProps) {
    return (
        <ObservabilityContext.Provider
            value={
                observability
            }
        >
            {children}
        </ObservabilityContext.Provider>
    );
}

export function useObservabilityPort():
    ObservabilityPort {
    const observability =
        useContext(
            ObservabilityContext,
        );

    if (
        observability
            === null
    ) {
        throw new Error(
            'ObservabilityContextProvider is required.',
        );
    }

    return observability;
}
