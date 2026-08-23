import {
    createBrowserMembershipHeaderParams,
    executeBrowserApiReadRequest,
    type BrowserApiClient,
    type BrowserMembershipLocator,
} from '@/platform/api';

export interface DiscoverBrowserWorkspacesOptions {
    readonly signal?: AbortSignal;
}

export async function discoverBrowserWorkspaces(
    client: BrowserApiClient,
    membershipId:
        BrowserMembershipLocator,
    options:
        DiscoverBrowserWorkspacesOptions = {},
) {
    const requestOptions = {
        params: {
            header:
                createBrowserMembershipHeaderParams({
                    membershipId,
                }),
        },

        ...(
            options.signal
                === undefined
                ? {}
                : {
                    signal:
                        options.signal,
                }
        ),
    };

    return executeBrowserApiReadRequest(
        () => client.GET(
            '/api/v1/user/my-workspaces',
            requestOptions,
        ),
    );
}
