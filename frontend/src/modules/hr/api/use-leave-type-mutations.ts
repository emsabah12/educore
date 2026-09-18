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
    leaveTypesQueryKey,
    type LeaveTypeResource,
} from '@/modules/hr/api/use-leave-types-query';
import {
    executeBrowserApiRequest,
    type BrowserApiFailure,
} from '@/platform/api';
import {
    createBrowserMembershipHeaderParams,
} from '@/platform/api/request-context';

export interface CreateLeaveTypeInput {
    readonly code: string;
    readonly name: string;
    readonly category: LeaveTypeResource['category'];
    readonly balance_mode: LeaveTypeResource['balance_mode'];
    readonly unit: LeaveTypeResource['unit'];
    readonly description: string | null;
}

function useMembershipId(): string | null {
    const workspaceState =
        useWorkspaceContextState();

    return workspaceState.status === 'ready'
        ? workspaceState.context.membership.id
        : null;
}

function useInvalidateLeaveTypes() {
    const queryClient =
        useQueryClient();

    return () => {
        void queryClient.invalidateQueries(
            {
                queryKey:
                    leaveTypesQueryKey,
            },
        );
    };
}

export function useCreateLeaveTypeMutation(): UseMutationResult<
    LeaveTypeResource,
    BrowserApiFailure,
    CreateLeaveTypeInput
> {
    const apiClient =
        useApiClient();

    const membershipId =
        useMembershipId();

    const invalidate =
        useInvalidateLeaveTypes();

    return useMutation<
        LeaveTypeResource,
        BrowserApiFailure,
        CreateLeaveTypeInput
    >({
        mutationFn: async (
            input,
        ) => {
            if (membershipId === null) {
                throw new Error(
                    'useCreateLeaveTypeMutation executed without a ready Membership context.',
                );
            }

            const result =
                await executeBrowserApiRequest(
                    apiClient.POST(
                        '/api/v1/hr/leave-types',
                        {
                            params: {
                                header:
                                    createBrowserMembershipHeaderParams(
                                        {
                                            membershipId,
                                        },
                                    ),
                            },

                            body:
                                input,
                        },
                    ),
                );

            if (! result.ok) {
                throw result;
            }

            if (result.data === undefined) {
                throw new Error(
                    'LeaveType creation response was empty.',
                );
            }

            return result.data.data;
        },

        onSuccess: () => {
            invalidate();
        },
    });
}

export function useDeactivateLeaveTypeMutation(): UseMutationResult<
    LeaveTypeResource,
    BrowserApiFailure,
    string
> {
    const apiClient =
        useApiClient();

    const membershipId =
        useMembershipId();

    const invalidate =
        useInvalidateLeaveTypes();

    return useMutation<
        LeaveTypeResource,
        BrowserApiFailure,
        string
    >({
        mutationFn: async (
            leaveTypeId,
        ) => {
            if (membershipId === null) {
                throw new Error(
                    'useDeactivateLeaveTypeMutation executed without a ready Membership context.',
                );
            }

            const result =
                await executeBrowserApiRequest(
                    apiClient.POST(
                        '/api/v1/hr/leave-types/{leaveTypeId}/deactivate',
                        {
                            params: {
                                path: {
                                    leaveTypeId,
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
                    'LeaveType deactivation response was empty.',
                );
            }

            return result.data.data;
        },

        onSuccess: () => {
            invalidate();
        },
    });
}
