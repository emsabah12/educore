import {
    executeBrowserApiRequest,
    type BrowserApiClient,
    type BrowserApiResult,
} from '@/platform/api';

import type {
    BrowserLoginRequest,
    BrowserLoginSuccess,
} from '@/platform/auth/contract';
import {
    createBrowserAuthAbortOptions,
} from '@/platform/auth/request-options';
import {
    initializeBrowserSession,
} from '@/platform/auth/session-bootstrap';

export interface BrowserLoginOptions {
    readonly signal?:
        AbortSignal;
}

export async function loginWithBrowserSession(
    client: BrowserApiClient,
    request: BrowserLoginRequest,
    options: BrowserLoginOptions = {},
): Promise<
    BrowserApiResult<
        BrowserLoginSuccess
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
            '/api/v1/browser/auth/login',
            {
                ...abortOptions,

                body:
                    request,
            },
        ),
    );
}