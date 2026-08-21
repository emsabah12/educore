import {
    createBrowserMembershipHeaderParams,
    createBrowserWorkspaceHeaderParams,
    executeBrowserApiRequest,
    type BrowserApiClient,
    type BrowserMembershipLocator,
    type OrganizationalAssignmentLocator,
} from '@/platform/api';

export interface ProjectBrowserCapabilitiesOptions {
    readonly signal?:
        AbortSignal;
}

export async function projectBrowserTenantCapabilities(
    client:
        BrowserApiClient,
    membershipId:
        BrowserMembershipLocator,
    options:
        ProjectBrowserCapabilitiesOptions = {},
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

    return executeBrowserApiRequest(
        client.GET(
            '/api/v1/core/authorization/capabilities',
            requestOptions,
        ),
    );
}

export async function projectBrowserWorkspaceCapabilities(
    client:
        BrowserApiClient,
    membershipId:
        BrowserMembershipLocator,
    organizationalAssignmentId:
        OrganizationalAssignmentLocator,
    options:
        ProjectBrowserCapabilitiesOptions = {},
) {
    const requestOptions = {
        params: {
            header:
                createBrowserWorkspaceHeaderParams({
                    membershipId,
                    organizationalAssignmentId,
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

    return executeBrowserApiRequest(
        client.GET(
            '/api/v1/core/authorization/workspace-capabilities',
            requestOptions,
        ),
    );
}
