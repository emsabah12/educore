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

export type LeaveTypeResource =
    ApiComponents['schemas']['LeaveTypeResource'];

function useMembershipId(): string | null {
    const workspaceState =
        useWorkspaceContextState();

    return workspaceState.status === 'ready'
        ? workspaceState.context.membership.id
        : null;
}

export const selfLeaveTypesQueryKey = [
    'hr',
    'self-leave-types',
] as const;

/**
 * §Perbaikan gap permission — reachable dengan hr.leave.self.read
 * saja (SUDAH dimiliki role `employee`), TIDAK seperti
 * useLeaveTypesQuery yang memanggil endpoint katalog HR
 * (`GET /leave-types`, butuh hr.leave.policy.read). Pakai hook ini,
 * BUKAN useLeaveTypesQuery, di halaman self-service mana pun.
 */
export function useSelfLeaveTypesQuery(): UseQueryResult<
    readonly LeaveTypeResource[],
    BrowserApiFailure
> {
    const apiClient =
        useApiClient();

    const membershipId =
        useMembershipId();

    return useQuery<
        readonly LeaveTypeResource[],
        BrowserApiFailure
    >({
        queryKey:
            selfLeaveTypesQueryKey,

        enabled:
            membershipId !== null,

        queryFn: async () => {
            if (membershipId === null) {
                throw new Error(
                    'useSelfLeaveTypesQuery executed without a ready Membership context.',
                );
            }

            const result =
                await executeBrowserApiReadRequest(
                    () =>
                        apiClient.GET(
                            '/api/v1/hr/self/leave-types',
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
