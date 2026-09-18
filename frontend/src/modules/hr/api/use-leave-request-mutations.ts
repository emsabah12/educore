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
    leaveRequestsQueryKey,
    type LeaveRequestResource,
} from '@/modules/hr/api/use-leave-requests-query';
import {
    executeBrowserApiRequest,
    type BrowserApiFailure,
} from '@/platform/api';
import {
    createBrowserMembershipHeaderParams,
} from '@/platform/api/request-context';

export interface CreateLeaveRequestInput {
    readonly employmentId: string;
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

function useInvalidateLeaveRequests(
    employmentId: string,
) {
    const queryClient =
        useQueryClient();

    return () => {
        void queryClient.invalidateQueries(
            {
                queryKey:
                    leaveRequestsQueryKey(
                        employmentId,
                    ),
            },
        );
    };
}

export function useCreateLeaveRequestMutation(
    employmentId: string,
): UseMutationResult<
    LeaveRequestResource,
    BrowserApiFailure,
    CreateLeaveRequestInput
> {
    const apiClient =
        useApiClient();

    const membershipId =
        useMembershipId();

    const invalidate =
        useInvalidateLeaveRequests(
            employmentId,
        );

    return useMutation<
        LeaveRequestResource,
        BrowserApiFailure,
        CreateLeaveRequestInput
    >({
        mutationFn: async (
            input,
        ) => {
            if (membershipId === null) {
                throw new Error(
                    'useCreateLeaveRequestMutation executed without a ready Membership context.',
                );
            }

            const result =
                await executeBrowserApiRequest(
                    apiClient.POST(
                        '/api/v1/hr/leave-requests',
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
                                employment_id:
                                    input.employmentId,

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
                    'LeaveRequest creation response was empty.',
                );
            }

            return result.data.data;
        },

        onSuccess: () => {
            invalidate();
        },
    });
}

function useLeaveRequestTransitionMutation(
    employmentId: string,
    path:
        | '/api/v1/hr/leave-requests/{leaveRequestId}/submit'
        | '/api/v1/hr/leave-requests/{leaveRequestId}/withdraw'
        | '/api/v1/hr/leave-requests/{leaveRequestId}/cancel'
        | '/api/v1/hr/leave-requests/{leaveRequestId}/approve'
        | '/api/v1/hr/leave-requests/{leaveRequestId}/reject',
    errorContext: string,
): UseMutationResult<
    LeaveRequestResource,
    BrowserApiFailure,
    { leaveRequestId: string; note?: string | null }
> {
    const apiClient =
        useApiClient();

    const membershipId =
        useMembershipId();

    const invalidate =
        useInvalidateLeaveRequests(
            employmentId,
        );

    return useMutation<
        LeaveRequestResource,
        BrowserApiFailure,
        { leaveRequestId: string; note?: string | null }
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

                            body: {
                                note:
                                    input.note
                                    ?? null,
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

export function useSubmitLeaveRequestMutation(
    employmentId: string,
): UseMutationResult<
    LeaveRequestResource,
    BrowserApiFailure,
    { leaveRequestId: string }
> {
    return useLeaveRequestTransitionMutation(
        employmentId,
        '/api/v1/hr/leave-requests/{leaveRequestId}/submit',
        'useSubmitLeaveRequestMutation',
    );
}

export function useWithdrawLeaveRequestMutation(
    employmentId: string,
): UseMutationResult<
    LeaveRequestResource,
    BrowserApiFailure,
    { leaveRequestId: string }
> {
    return useLeaveRequestTransitionMutation(
        employmentId,
        '/api/v1/hr/leave-requests/{leaveRequestId}/withdraw',
        'useWithdrawLeaveRequestMutation',
    );
}

export function useCancelLeaveRequestMutation(
    employmentId: string,
): UseMutationResult<
    LeaveRequestResource,
    BrowserApiFailure,
    { leaveRequestId: string; note?: string | null }
> {
    return useLeaveRequestTransitionMutation(
        employmentId,
        '/api/v1/hr/leave-requests/{leaveRequestId}/cancel',
        'useCancelLeaveRequestMutation',
    );
}

export function useApproveLeaveRequestMutation(
    employmentId: string,
): UseMutationResult<
    LeaveRequestResource,
    BrowserApiFailure,
    { leaveRequestId: string; note?: string | null }
> {
    return useLeaveRequestTransitionMutation(
        employmentId,
        '/api/v1/hr/leave-requests/{leaveRequestId}/approve',
        'useApproveLeaveRequestMutation',
    );
}

export function useRejectLeaveRequestMutation(
    employmentId: string,
): UseMutationResult<
    LeaveRequestResource,
    BrowserApiFailure,
    { leaveRequestId: string; note?: string | null }
> {
    return useLeaveRequestTransitionMutation(
        employmentId,
        '/api/v1/hr/leave-requests/{leaveRequestId}/reject',
        'useRejectLeaveRequestMutation',
    );
}
