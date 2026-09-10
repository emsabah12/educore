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

export type EmploymentTypeResource =
    ApiComponents['schemas']['EmploymentTypeResource'];

/*
 * Employment Type is a tenant-wide catalog (HR-002 §3), not
 * scoped to a particular organizational workspace — unlike the
 * HR Workforce hooks this only needs a ready Membership
 * context, matching useTenantRolesQuery's pattern.
 */
function useMembershipId(): string | null {
    const workspaceState =
        useWorkspaceContextState();

    return workspaceState.status === 'ready'
        ? workspaceState.context.membership.id
        : null;
}

export const employmentTypesQueryKey = [
    'hr',
    'employment-types',
] as const;

export function useEmploymentTypesQuery(): UseQueryResult<
    readonly EmploymentTypeResource[],
    BrowserApiFailure
> {
    const apiClient =
        useApiClient();

    const membershipId =
        useMembershipId();

    return useQuery<
        readonly EmploymentTypeResource[],
        BrowserApiFailure
    >({
        queryKey:
            employmentTypesQueryKey,

        enabled:
            membershipId !== null,

        queryFn: async () => {
            if (membershipId === null) {
                throw new Error(
                    'useEmploymentTypesQuery executed without a ready Membership context.',
                );
            }

            const result =
                await executeBrowserApiReadRequest(
                    () =>
                        apiClient.GET(
                            '/api/v1/hr/employment-types',
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