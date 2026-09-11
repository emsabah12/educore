import {
    useQuery,
    type UseQueryResult,
} from '@tanstack/react-query';

import {
    useApiClient,
} from '@/app/api/ApiClientProvider';
import {
    useWorkspaceContextState,
} from '@/app/workspace/WorkspaceContextProvider';
import type {
    ApiComponents,
} from '@/platform/api';
import {
    executeBrowserApiReadRequest,
    type BrowserApiFailure,
} from '@/platform/api';
import {
    createBrowserMembershipHeaderParams,
} from '@/platform/api/request-context';

export type OrganizationalAssignmentResource =
    ApiComponents['schemas']['OrganizationalAssignmentResource'];

function useMembershipId(): string | null {
    const workspaceState =
        useWorkspaceContextState();

    return workspaceState.status === 'ready'
        ? workspaceState.context.membership.id
        : null;
}

export function organizationalAssignmentsQueryKey(
    organizationId:
        string,
) {
    return [
        'settings',
        'organizations',
        organizationId,
        'assignments',
    ] as const;
}

/*
 * Always fetches the FULL set (organization-level + every Unit
 * combined) — no organization_unit_id filter. OrganizationMembersPage
 * needs the whole picture in one table, not a per-Unit slice
 * (that is what the backend's optional filter exists for, but this
 * page doesn't use it).
 */
export function useOrganizationalAssignmentsQuery(
    organizationId:
        string | null,
): UseQueryResult<
    readonly OrganizationalAssignmentResource[],
    BrowserApiFailure
> {
    const apiClient =
        useApiClient();

    const membershipId =
        useMembershipId();

    return useQuery<
        readonly OrganizationalAssignmentResource[],
        BrowserApiFailure
    >({
        queryKey:
            organizationalAssignmentsQueryKey(
                organizationId
                ?? '',
            ),

        enabled:
            membershipId !== null
            && organizationId !== null,

        queryFn: async () => {
            if (
                membershipId === null
                || organizationId === null
            ) {
                throw new Error(
                    'useOrganizationalAssignmentsQuery executed without a ready Membership context or organizationId.',
                );
            }

            const result =
                await executeBrowserApiReadRequest(
                    () =>
                        apiClient.GET(
                            '/api/v1/core/organizations/{organization}/assignments',
                            {
                                params: {
                                    path: {
                                        organization:
                                            organizationId,
                                    },

                                    header:
                                        createBrowserMembershipHeaderParams(
                                            {
                                                membershipId,
                                            },
                                        ),
                                },
                            },
                        ),
                );

            if (! result.ok) {
                throw result;
            }

            return (
                result.data?.data
                ?? []
            );
        },
    });
}
