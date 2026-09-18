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

export type LeaveEntitlementResource =
    ApiComponents['schemas']['LeaveEntitlementResource'];

function useMembershipId(): string | null {
    const workspaceState =
        useWorkspaceContextState();

    return workspaceState.status === 'ready'
        ? workspaceState.context.membership.id
        : null;
}

export function leaveEntitlementsQueryKey(
    employmentId: string,
): readonly unknown[] {
    return [
        'hr',
        'leave-entitlements',
        employmentId,
    ];
}

export function useLeaveEntitlementsQuery(
    employmentId: string,
): UseQueryResult<
    readonly LeaveEntitlementResource[],
    BrowserApiFailure
> {
    const apiClient =
        useApiClient();

    const membershipId =
        useMembershipId();

    return useQuery<
        readonly LeaveEntitlementResource[],
        BrowserApiFailure
    >({
        queryKey:
            leaveEntitlementsQueryKey(
                employmentId,
            ),

        enabled:
            membershipId !== null
            && employmentId !== '',

        queryFn: async () => {
            if (membershipId === null) {
                throw new Error(
                    'useLeaveEntitlementsQuery executed without a ready Membership context.',
                );
            }

            const result =
                await executeBrowserApiReadRequest(
                    () =>
                        apiClient.GET(
                            '/api/v1/hr/employments/{employmentId}/leave-entitlements',
                            {
                                params: {
                                    path: {
                                        employmentId,
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
