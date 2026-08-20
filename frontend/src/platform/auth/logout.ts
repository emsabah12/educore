import {
    executeBrowserApiRequest,
    type BrowserApiClient,
    type BrowserApiResult,
} from '@/platform/api';

import type {
    BrowserLogoutSuccess,
} from '@/platform/auth/contract';
import { createBrowserAuthAbortOptions } from '@/platform/auth/request-options';

export interface BrowserLogoutOptions {
    readonly signal?: AbortSignal;
}

export async function logoutBrowserSession(
    client: BrowserApiClient,
    options: BrowserLogoutOptions = {},
): Promise<
    BrowserApiResult<BrowserLogoutSuccess>
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
            '/api/v1/browser/auth/logout',
            abortOptions,
        ),
    );
}
