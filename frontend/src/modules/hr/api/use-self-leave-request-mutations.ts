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
import type {
    LeaveRequestResource,
} from '@/modules/hr/api/use-leave-requests-query';
import {
    selfLeaveRequestsQueryKey,
} from '@/modules/hr/api/use-self-leave-requests-query';
import {
    executeBrowserApiRequest,
    type BrowserApiFailure,
} from '@/platform/api';
import {
    createBrowserMembershipHeaderParams,
} from '@/platform/api/request-context';

export interface CreateSelfLeaveRequestInput {
    readonly leaveTypeId: string;
    readonly startsAt: string;
    readonly endsAt: string;
    readonly requestTimezone: string;
    readonly requestedUnits: number;
    readonly reason: string | null;
}

function useMembershipId(): string | null {
    const workspaceState =
        useWorkspaceContextState();

    return workspaceState.status === 'ready'
        ? workspaceState.context.membership.id
        : null;
}

function useInvalidateSelfLeaveRequests() {
    const queryClient =
        useQueryClient();

    return () => {
        void queryClient.invalidateQueries(
            {
                queryKey:
                    selfLeaveRequestsQueryKey,
            },
        );
    };
}

export function useCreateSelfLeaveRequestMutation(): UseMutationResult<
    LeaveRequestResource,
    BrowserApiFailure,
    CreateSelfLeaveRequestInput
> {
    const apiClient =
        useApiClient();

    const membershipId =
        useMembershipId();

    const invalidate =
        useInvalidateSelfLeaveRequests();

    return useMutation<
        LeaveRequestResource,
        BrowserApiFailure,
        CreateSelfLeaveRequestInput
    >({
        mutationFn: async (
            input,
        ) => {
            if (membershipId === null) {
                throw new Error(
                    'useCreateSelfLeaveRequestMutation executed without a ready Membership context.',
                );
            }

            const result =
                await executeBrowserApiRequest(
                    apiClient.POST(
                        '/api/v1/hr/self/leave-requests',
                        {
                            params: {
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

                                starts_at:
                                    input.startsAt,

                                ends_at:
                                    input.endsAt,

                                request_timezone:
                                    input.requestTimezone,

                                requested_units:
                                    input.requestedUnits,

                                reason:
                                    input.reason,
                            },
                        },
                    ),
                );

            if (! result.ok) {
                throw result;
            }

            if (result.data === undefined) {
                throw new Error(
                    'Self-service LeaveRequest creation response was empty.',
                );
            }

            return result.data.data;
        },

        onSuccess: () => {
            invalidate();
        },
    });
}

function useSelfLeaveRequestTransitionMutation(
    path:
        | '/api/v1/hr/self/leave-requests/{leaveRequestId}/submit'
        | '/api/v1/hr/self/leave-requests/{leaveRequestId}/withdraw',
    errorContext: string,
): UseMutationResult<
    LeaveRequestResource,
    BrowserApiFailure,
    { leaveRequestId: string }
> {
    const apiClient =
        useApiClient();

    const membershipId =
        useMembershipId();

    const invalidate =
        useInvalidateSelfLeaveRequests();

    return useMutation<
        LeaveRequestResource,
        BrowserApiFailure,
        { leaveRequestId: string }
    >({
        mutationFn: async (
            input,
        ) => {
            if (membershipId === null) {
                throw new Error(
                    `${errorContext} executed without a ready Membership context.`,
                );
            }

            const result =
                await executeBrowserApiRequest(
                    apiClient.POST(
                        path,
                        {
                            params: {
                                path: {
                                    leaveRequestId:
                                        input.leaveRequestId,
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

            if (result.data === undefined) {
                throw new Error(
                    `${errorContext} response was empty.`,
                );
            }

            return result.data.data;
        },

        onSuccess: () => {
            invalidate();
        },
    });
}

export function useSubmitSelfLeaveRequestMutation(): UseMutationResult<
    LeaveRequestResource,
    BrowserApiFailure,
    { leaveRequestId: string }
> {
    return useSelfLeaveRequestTransitionMutation(
        '/api/v1/hr/self/leave-requests/{leaveRequestId}/submit',
        'useSubmitSelfLeaveRequestMutation',
    );
}

export function useWithdrawSelfLeaveRequestMutation(): UseMutationResult<
    LeaveRequestResource,
    BrowserApiFailure,
    { leaveRequestId: string }
> {
    return useSelfLeaveRequestTransitionMutation(
        '/api/v1/hr/self/leave-requests/{leaveRequestId}/withdraw',
        'useWithdrawSelfLeaveRequestMutation',
    );
}
