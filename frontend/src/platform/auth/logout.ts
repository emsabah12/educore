import {
    executeBrowserApiRequest,
    type BrowserApiClient,
    type BrowserApiResult,
} from '@/platform/api';

import type {
    BrowserLogoutSuccess,
} from '@/platform/auth/contract';
import {
    createBrowserAuthAbortOptions,
} from '@/platform/auth/request-options';
import {
    initializeBrowserSession,
} from '@/platform/auth/session-bootstrap';

export interface BrowserLogoutOptions {
    readonly signal?:
        AbortSignal;
}

export async function logoutBrowserSession(
    client: BrowserApiClient,
    options: BrowserLogoutOptions = {},
): Promise<
    BrowserApiResult<
        BrowserLogoutSuccess
    >
> {
    const abortOptions =
        createBrowserAuthAbortOptions(
            options.signal,
        );

    const sessionResult =
        await initializeBrowserSession(
            client,
            abortOptions,
        );

    if (! sessionResult.ok) {
        return sessionResult;
    }

    return executeBrowserApiRequest(
        client.POST(
            '/api/v1/browser/auth/logout',
            abortOptions,
        ),
    );
}