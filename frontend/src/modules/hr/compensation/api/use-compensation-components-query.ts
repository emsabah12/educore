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

export type CompensationComponentResource =
    ApiComponents['schemas']['CompensationComponentResource'];

/*
 * Compensation Component is a tenant-wide catalog (HR-006 §7.2),
 * not Organizational Workspace scope — sama seperti
 * useEmploymentTypesQuery, cukup Membership context.
 */
function useMembershipId(): string | null {
    const workspaceState =
        useWorkspaceContextState();

    return workspaceState.status === 'ready'
        ? workspaceState.context.membership.id
        : null;
}

export const compensationComponentsQueryKey = [
    'hr',
    'compensation-components',
] as const;

export function useCompensationComponentsQuery(): UseQueryResult<
    readonly CompensationComponentResource[],
    BrowserApiFailure
> {
    const apiClient =
        useApiClient();

    const membershipId =
        useMembershipId();

    return useQuery<
        readonly CompensationComponentResource[],
        BrowserApiFailure
    >({
        queryKey:
            compensationComponentsQueryKey,

        enabled:
            membershipId !== null,

        queryFn: async () => {
            if (membershipId === null) {
                throw new Error(
                    'useCompensationComponentsQuery executed without a ready Membership context.',
                );
            }

            const result =
                await executeBrowserApiReadRequest(
                    () =>
                        apiClient.GET(
                            '/api/v1/hr/compensation/components',
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

            return result.data?.data ?? [];
        },
    });
}
