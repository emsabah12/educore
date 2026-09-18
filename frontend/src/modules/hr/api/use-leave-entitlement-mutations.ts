import {
    useMutation,
    useQueryClient,
    type UseMutationResult,
} from '@tanstack/react-query';

import {
    useApiClient,
} from '@/app/api/ApiClientProvider';
import {
    useWorkspaceContextState,
} from '@/app/workspace/WorkspaceContextProvider';
import {
    leaveEntitlementsQueryKey,
    type LeaveEntitlementResource,
} from '@/modules/hr/api/use-leave-entitlements-query';
import {
    executeBrowserApiRequest,
    type BrowserApiFailure,
} from '@/platform/api';
import {
    createBrowserMembershipHeaderParams,
} from '@/platform/api/request-context';

export interface GenerateLeaveEntitlementInput {
    readonly employmentId: string;
    readonly leaveTypeId: string;
    readonly periodStart: string;
    readonly periodEnd: string;
}

export interface AdjustLeaveEntitlementInput {
    readonly entitlementId: string;
    readonly unitsDelta: number;
    readonly reason: string | null;
    readonly idempotencyKey: string;
}

export interface AdjustLeaveEntitlementResult {
    readonly entitlement_id: string;
    readonly balance: string;
}

function useMembershipId(): string | null {
    const workspaceState =
        useWorkspaceContextState();

    return workspaceState.status === 'ready'
        ? workspaceState.context.membership.id
        : null;
}

export function useGenerateLeaveEntitlementMutation(): UseMutationResult<
    LeaveEntitlementResource,
    BrowserApiFailure,
    GenerateLeaveEntitlementInput
> {
    const apiClient =
        useApiClient();

    const membershipId =
        useMembershipId();

    const queryClient =
        useQueryClient();

    return useMutation<
        LeaveEntitlementResource,
        BrowserApiFailure,
        GenerateLeaveEntitlementInput
    >({
        mutationFn: async (
            input,
        ) => {
            if (membershipId === null) {
                throw new Error(
                    'useGenerateLeaveEntitlementMutation executed without a ready Membership context.',
                );
            }

            const result =
                await executeBrowserApiRequest(
                    apiClient.POST(
                        '/api/v1/hr/employments/{employmentId}/leave-entitlements/generate',
                        {
                            params: {
                                path: {
                                    employmentId:
                                        input.employmentId,
                                },

                                header:
                                    createBrowserMembershipHeaderParams(
                                        {
                                            membershipId,
                                        },
                                    ),
                            },

                            body: {
                                leave_type_id:
                                    input.leaveTypeId,

                                period_start:
                                    input.periodStart,

                                period_end:
                                    input.periodEnd,
                            },
                        },
                    ),
                );

            if (! result.ok) {
                throw result;
            }

            if (result.data === undefined) {
                throw new Error(
                    'LeaveEntitlement generation response was empty.',
                );
            }

            return result.data.data;
        },

        onSuccess: (
            _data,
            variables,
        ) => {
            void queryClient.invalidateQueries(
                {
                    queryKey:
                        leaveEntitlementsQueryKey(
                            variables.employmentId,
                        ),
                },
            );
        },
    });
}

export function useAdjustLeaveEntitlementMutation(): UseMutationResult<
    AdjustLeaveEntitlementResult,
    BrowserApiFailure,
    AdjustLeaveEntitlementInput
> {
    const apiClient =
        useApiClient();

    const membershipId =
        useMembershipId();

    return useMutation<
        AdjustLeaveEntitlementResult,
        BrowserApiFailure,
        AdjustLeaveEntitlementInput
    >({
        mutationFn: async (
            input,
        ) => {
            if (membershipId === null) {
                throw new Error(
                    'useAdjustLeaveEntitlementMutation executed without a ready Membership context.',
                );
            }

            const result =
                await executeBrowserApiRequest(
                    apiClient.POST(
                        '/api/v1/hr/leave-entitlements/{entitlementId}/adjustments',
                        {
                            params: {
                                path: {
                                    entitlementId:
                                        input.entitlementId,
                                },

                                header:
                                    createBrowserMembershipHeaderParams(
                                        {
                                            membershipId,
                                        },
                                    ),
                            },

                            body: {
                                units_delta:
                                    input.unitsDelta,

                                reason:
                                    input.reason,

                                idempotency_key:
                                    input.idempotencyKey,
                            },
                        },
                    ),
                );

            if (! result.ok) {
                throw result;
            }

            if (result.data === undefined) {
                throw new Error(
                    'LeaveEntitlement adjustment response was empty.',
                );
            }

            return result.data.data;
        },
    });
}
