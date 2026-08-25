import {
    createBrowserMembershipHeaderParams,
    executeBrowserApiReadRequest,
    type BrowserApiClient,
    type BrowserApiResult,
    type BrowserMembershipLocator,
} from '@/platform/api';

import type {
    AuthenticatedBootstrapSuccess,
} from '@/platform/auth/contract';
import {
    createBrowserAuthAbortOptions,
} from '@/platform/auth/request-options';
import {
    initializeBrowserSession,
} from '@/platform/auth/session-bootstrap';

export interface BrowserAuthBootstrapOptions {
    readonly membershipId?:
        BrowserMembershipLocator;

    readonly signal?:
        AbortSignal;
}

export async function bootstrapBrowserAuthentication(
    client: BrowserApiClient,
    options: BrowserAuthBootstrapOptions = {},
): Promise<
    BrowserApiResult<
        AuthenticatedBootstrapSuccess
    >
> {
    const abortOptions =
        createBrowserAuthAbortOptions(
            options.signal,
        );

    if (
        options.membershipId
            === undefined
    ) {
        /*
         * Initial BrowserAuth bootstrap has no Membership
         * locator yet.
         *
         * Establish BrowserSession transport first so the
         * dual-transport canonical endpoint cannot mistake
         * the first-party SPA for a bearer-only client.
         */
        const sessionResult =
            await initializeBrowserSession(
                client,
                abortOptions,
            );

        if (! sessionResult.ok) {
            return sessionResult;
        }

        return executeBrowserApiReadRequest(
            () => client.GET(
                '/api/v1/auth/me',
                abortOptions,
            ),
        );
    }

    /*
     * A Membership-specific bootstrap only occurs after an
     * existing BrowserSession lifecycle:
     *
     * - successful Browser login, or
     * - Browser Membership switching.
     *
     * Re-bootstrap of the session is therefore deliberately
     * unnecessary here.
     */
    const membershipId =
        options.membershipId;

    return executeBrowserApiReadRequest(
        () => client.GET(
            '/api/v1/auth/me',
            {
                ...abortOptions,

                params: {
                    header:
                        createBrowserMembershipHeaderParams({
                            membershipId,
                        }),
                },
            },
        ),
    );
}
