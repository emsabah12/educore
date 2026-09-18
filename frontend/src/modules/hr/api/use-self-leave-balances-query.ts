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

export type LeaveEntitlementBalanceEntry =
    ApiComponents['schemas']['LeaveEntitlementBalanceEntry'];

function useMembershipId(): string | null {
    const workspaceState =
        useWorkspaceContextState();

    return workspaceState.status === 'ready'
        ? workspaceState.context.membership.id
        : null;
}

export const selfLeaveBalancesQueryKey = [
    'hr',
    'self-leave-balances',
] as const;

export function useSelfLeaveBalancesQuery(): UseQueryResult<
    readonly LeaveEntitlementBalanceEntry[],
    BrowserApiFailure
> {
    const apiClient =
        useApiClient();

    const membershipId =
        useMembershipId();

    return useQuery<
        readonly LeaveEntitlementBalanceEntry[],
        BrowserApiFailure
    >({
        queryKey:
            selfLeaveBalancesQueryKey,

        enabled:
            membershipId !== null,

        queryFn: async () => {
            if (membershipId === null) {
                throw new Error(
                    'useSelfLeaveBalancesQuery executed without a ready Membership context.',
                );
            }

            const result =
                await executeBrowserApiReadRequest(
                    () =>
                        apiClient.GET(
                            '/api/v1/hr/self/leave-balances',
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
