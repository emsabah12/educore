import type {
    BrowserApiClient,
    BrowserMembershipLocator,
} from '@/platform/api';

import {
    discoverBrowserWorkspaces,
    type DiscoverBrowserWorkspacesOptions,
} from '@/platform/workspace/discovery';

export interface WorkspaceContextOperations {
    discover(
        membershipId:
            BrowserMembershipLocator,
        options?:
            DiscoverBrowserWorkspacesOptions,
    ): ReturnType<
        typeof discoverBrowserWorkspaces
    >;
}

export function createWorkspaceContextOperations(
    client: BrowserApiClient,
): WorkspaceContextOperations {
    return {
        discover(
            membershipId,
            options,
        ) {
            return discoverBrowserWorkspaces(
                client,
                membershipId,
                options,
            );
        },
    };
}
