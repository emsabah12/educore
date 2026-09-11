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

export type OrganizationUnitResource =
    ApiComponents['schemas']['OrganizationUnitResource'];

/*
 * TENANT-level, same as useOrganizationsQuery — only needs a ready
 * Membership context, never an organizational Workspace selection.
 */
function useMembershipId(): string | null {
    const workspaceState =
        useWorkspaceContextState();

    return workspaceState.status === 'ready'
        ? workspaceState.context.membership.id
        : null;
}

export function organizationUnitsQueryKey(
    organizationId:
        string,
) {
    return [
        'settings',
        'organizations',
        organizationId,
        'units',
    ] as const;
}

/*
 * `organizationId` is nullable so callers can mount this hook
 * before useParams has resolved a value (mirrors
 * useWorkspaceEmployeeDetailQuery's employeeId convention).
 */
export function useOrganizationUnitsQuery(
    organizationId:
        string | null,
): UseQueryResult<
    readonly OrganizationUnitResource[],
    BrowserApiFailure
> {
    const apiClient =
        useApiClient();

    const membershipId =
        useMembershipId();

    return useQuery<
        readonly OrganizationUnitResource[],
        BrowserApiFailure
    >({
        queryKey:
            organizationUnitsQueryKey(
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
                    'useOrganizationUnitsQuery executed without a ready Membership context or organizationId.',
                );
            }

            const result =
                await executeBrowserApiReadRequest(
                    () =>
                        apiClient.GET(
                            '/api/v1/core/organizations/{organization}/units',
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
