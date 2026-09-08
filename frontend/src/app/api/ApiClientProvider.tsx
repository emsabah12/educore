import {
    createContext,
    type PropsWithChildren,
    useContext,
} from 'react';

import type {
    BrowserApiClient,
} from '@/platform/api';

const ApiClientContext =
    createContext<
        BrowserApiClient | null
    >(null);

export interface ApiClientProviderProps
    extends PropsWithChildren {
    readonly apiClient:
        BrowserApiClient;
}

/*
 * A single BrowserApiClient instance is created once for
 * the entire application lifecycle (see app/runtime.ts) and
 * threaded through here so every business module reuses the
 * same client rather than constructing its own.
 *
 * This provider carries no lifecycle of its own — the
 * client is a stable singleton for as long as the
 * application runtime exists.
 */
export function ApiClientProvider({
    apiClient,
    children,
}: ApiClientProviderProps) {
    return (
        <ApiClientContext.Provider
            value={apiClient}
        >
            {children}
        </ApiClientContext.Provider>
    );
}

/*
 * Business module API adapters call this to reach the
 * canonical platform HTTP client. Throwing when the
 * provider is missing fails loudly at the exact call site
 * that forgot to compose ApiClientProvider, rather than
 * surfacing as a confusing downstream network failure.
 */
export function useApiClient(): BrowserApiClient {
    const apiClient =
        useContext(
            ApiClientContext,
        );

    if (apiClient === null) {
        throw new Error(
            'useApiClient must be used within an ApiClientProvider.',
        );
    }

    return apiClient;
}