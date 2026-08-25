import {
    executeBrowserApiRequest,
    type BrowserApiClient,
} from '@/platform/api';

import {
    createBrowserAuthAbortOptions,
} from '@/platform/auth/request-options';

export interface BrowserSessionBootstrapOptions {
    readonly signal?:
        AbortSignal;
}

/*
 * Establish or resume the first-party Laravel BrowserSession
 * before a BrowserSession-aware canonical resource is used.
 *
 * This operation never authenticates a user and never exposes
 * canonical bearer credentials to browser JavaScript.
 */
export async function initializeBrowserSession(
    client: BrowserApiClient,
    options: BrowserSessionBootstrapOptions = {},
) {
    const abortOptions =
        createBrowserAuthAbortOptions(
            options.signal,
        );

    return executeBrowserApiRequest(
        client.GET(
            '/api/v1/browser/session/csrf',
            abortOptions,
        ),
    );
}
