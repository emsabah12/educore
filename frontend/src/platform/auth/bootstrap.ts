import {
    createBrowserMembershipHeaderParams,
    executeBrowserApiRequest,
    type BrowserApiClient,
    type BrowserApiResult,
    type BrowserMembershipLocator,
} from '@/platform/api';

import type {
    AuthenticatedBootstrapSuccess,
} from '@/platform/auth/contract';
import { createBrowserAuthAbortOptions } from '@/platform/auth/request-options';

export interface BrowserAuthBootstrapOptions {
    readonly membershipId?:
        BrowserMembershipLocator;

    readonly signal?: AbortSignal;
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
        return executeBrowserApiRequest(
            client.GET(
                '/api/v1/auth/me',
                abortOptions,
            ),
        );
    }

    return executeBrowserApiRequest(
        client.GET(
            '/api/v1/auth/me',
            {
                ...abortOptions,

                params: {
                    header:
                        createBrowserMembershipHeaderParams({
                            membershipId:
                                options.membershipId,
                        }),
                },
            },
        ),
    );
}
