import {
    executeBrowserApiRequest,
    type BrowserApiClient,
    type BrowserApiResult,
} from '@/platform/api';

import type {
    BrowserLoginRequest,
    BrowserLoginSuccess,
} from '@/platform/auth/contract';
import { createBrowserAuthAbortOptions } from '@/platform/auth/request-options';

export interface BrowserLoginOptions {
    readonly signal?: AbortSignal;
}

export async function loginWithBrowserSession(
    client: BrowserApiClient,
    request: BrowserLoginRequest,
    options: BrowserLoginOptions = {},
): Promise<
    BrowserApiResult<BrowserLoginSuccess>
> {
    const abortOptions =
        createBrowserAuthAbortOptions(
            options.signal,
        );

    const csrfResult =
        await executeBrowserApiRequest(
            client.GET(
                '/api/v1/browser/session/csrf',
                abortOptions,
            ),
        );

    if (! csrfResult.ok) {
        return csrfResult;
    }

    return executeBrowserApiRequest(
        client.POST(
            '/api/v1/browser/auth/login',
            {
                ...abortOptions,
                body: request,
            },
        ),
    );
}
