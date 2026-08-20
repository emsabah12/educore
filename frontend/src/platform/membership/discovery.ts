import {
    executeBrowserApiRequest,
    type BrowserApiClient,
    type BrowserApiResult,
} from '@/platform/api';

import type {
    MembershipListSuccess,
} from '@/platform/membership/contract';
import { createMembershipRequestAbortOptions } from '@/platform/membership/request-options';

export interface DiscoverBrowserMembershipsOptions {
    readonly signal?: AbortSignal;
}

export async function discoverBrowserMemberships(
    client: BrowserApiClient,
    options:
        DiscoverBrowserMembershipsOptions = {},
): Promise<
    BrowserApiResult<
        MembershipListSuccess
    >
> {
    return executeBrowserApiRequest(
        client.GET(
            '/api/v1/user/my-memberships',
            createMembershipRequestAbortOptions(
                options.signal,
            ),
        ),
    );
}
