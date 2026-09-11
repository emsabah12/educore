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

export type OrganizationResource =
    ApiComponents['schemas']['OrganizationResource'];

/*
 * Kelola Organisasi is a TENANT-level concern (see
 * OrganizationManagementController on the backend) — unlike HR
 * Workforce hooks, this only needs a ready Membership context,
 * never an organizational Workspace selection. Organisasi itu
 * sendiri adalah prasyarat untuk Workspace organizational lain,
 * jadi mensyaratkannya di sini akan jadi lingkaran setan.
 */
function useMembershipId(): string | null {
    const workspaceState =
        useWorkspaceContextState();

    return workspaceState.status === 'ready'
        ? workspaceState.context.membership.id
        : null;
}

export const organizationsQueryKey = [
    'settings',
    'organizations',
] as const;

export function useOrganizationsQuery(): UseQueryResult<
    readonly OrganizationResource[],
    BrowserApiFailure
> {
    const apiClient =
        useApiClient();

    const membershipId =
        useMembershipId();

    return useQuery<
        readonly OrganizationResource[],
        BrowserApiFailure
    >({
        queryKey:
            organizationsQueryKey,

        enabled:
            membershipId !== null,

        queryFn: async () => {
            if (membershipId === null) {
                throw new Error(
                    'useOrganizationsQuery executed without a ready Membership context.',
                );
            }

            const result =
                await executeBrowserApiReadRequest(
                    () =>
                        apiClient.GET(
                            '/api/v1/core/organizations',
                            {
                                params: {
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
