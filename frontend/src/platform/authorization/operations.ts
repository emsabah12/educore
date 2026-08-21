import type {
    BrowserApiClient,
    BrowserMembershipLocator,
    OrganizationalAssignmentLocator,
} from '@/platform/api';

import {
    projectBrowserTenantCapabilities,
    projectBrowserWorkspaceCapabilities,
    type ProjectBrowserCapabilitiesOptions,
} from '@/platform/authorization/projection';

export interface CapabilityProjectionOperations {
    projectTenant(
        membershipId:
            BrowserMembershipLocator,
        options?:
            ProjectBrowserCapabilitiesOptions,
    ): ReturnType<
        typeof projectBrowserTenantCapabilities
    >;

    projectWorkspace(
        membershipId:
            BrowserMembershipLocator,
        organizationalAssignmentId:
            OrganizationalAssignmentLocator,
        options?:
            ProjectBrowserCapabilitiesOptions,
    ): ReturnType<
        typeof projectBrowserWorkspaceCapabilities
    >;
}

export function createCapabilityProjectionOperations(
    client:
        BrowserApiClient,
): CapabilityProjectionOperations {
    return {
        projectTenant(
            membershipId,
            options,
        ) {
            return projectBrowserTenantCapabilities(
                client,
                membershipId,
                options,
            );
        },

        projectWorkspace(
            membershipId,
            organizationalAssignmentId,
            options,
        ) {
            return projectBrowserWorkspaceCapabilities(
                client,
                membershipId,
                organizationalAssignmentId,
                options,
            );
        },
    };
}
