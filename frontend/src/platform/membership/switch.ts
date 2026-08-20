import {
    executeBrowserApiRequest,
    type BrowserApiClient,
    type BrowserApiResult,
} from '@/platform/api';

import type {
    BrowserMembershipSwitchSuccess,
    BrowserMembershipSwitchTarget,
} from '@/platform/membership/contract';
import { createMembershipRequestAbortOptions } from '@/platform/membership/request-options';

export interface SwitchBrowserMembershipOptions {
    readonly signal?: AbortSignal;
}

export async function switchBrowserMembership(
    client: BrowserApiClient,
    membershipId:
        BrowserMembershipSwitchTarget,
    options:
        SwitchBrowserMembershipOptions = {},
): Promise<
    BrowserApiResult<
        BrowserMembershipSwitchSuccess
    >
> {
    const abortOptions =
        createMembershipRequestAbortOptions(
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
            '/api/v1/browser/user/memberships/{membership_id}/switch',
            {
                ...abortOptions,

                params: {
                    path: {
                        membership_id:
                            membershipId,
                    },
                },
            },
        ),
    );
}
