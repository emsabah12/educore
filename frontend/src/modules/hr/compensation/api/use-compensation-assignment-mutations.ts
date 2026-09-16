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
    compensationAssignmentsQueryKey,
    type CompensationAssignmentResource,
} from '@/modules/hr/compensation/api/use-compensation-assignments-query';
import {
    executeBrowserApiRequest,
    type BrowserApiFailure,
} from '@/platform/api';
import {
    createBrowserMembershipHeaderParams,
} from '@/platform/api/request-context';

export interface CompensationAssignmentDraftInput {
    readonly compensation_component_id: string;
    readonly employment_position_assignment_id: string | null;
    readonly amount: number | null;
    readonly rate: number | null;
    readonly currency_code: string;
    readonly effective_from: string;
    readonly effective_to: string | null;
    readonly reason: string | null;
}

function useMembershipId(): string | null {
    const workspaceState =
        useWorkspaceContextState();

    return workspaceState.status === 'ready'
        ? workspaceState.context.membership.id
        : null;
}

function useInvalidateAssignments() {
    const queryClient =
        useQueryClient();

    return (
        employmentId: string,
    ) => {
        void queryClient.invalidateQueries(
            {
                queryKey:
                    compensationAssignmentsQueryKey(
                        employmentId,
                    ),
            },
        );
    };
}

export function useCreateCompensationAssignmentMutation(
    employmentId: string,
): UseMutationResult<
    CompensationAssignmentResource,
    BrowserApiFailure,
    CompensationAssignmentDraftInput
> {
    const apiClient =
        useApiClient();

    const membershipId =
        useMembershipId();

    const invalidate =
        useInvalidateAssignments();

    return useMutation<
        CompensationAssignmentResource,
        BrowserApiFailure,
        CompensationAssignmentDraftInput
    >({
        mutationFn: async (
            input,
        ) => {
            if (membershipId === null) {
                throw new Error(
                    'useCreateCompensationAssignmentMutation executed without a ready Membership context.',
                );
            }

            const result =
                await executeBrowserApiRequest(
                    apiClient.POST(
                        '/api/v1/hr/employments/{employmentId}/compensation-assignments',
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
                    'Compensation Assignment draft creation response was empty.',
                );
            }

            return result.data.data;
        },

        onSuccess: () => {
            invalidate(
                employmentId,
            );
        },
    });
}

export function useApproveCompensationAssignmentMutation(
    employmentId: string,
): UseMutationResult<
    CompensationAssignmentResource,
    BrowserApiFailure,
    string
> {
    const apiClient =
        useApiClient();

    const membershipId =
        useMembershipId();

    const invalidate =
        useInvalidateAssignments();

    return useMutation<
        CompensationAssignmentResource,
        BrowserApiFailure,
        string
    >({
        mutationFn: async (
            assignmentId,
        ) => {
            if (membershipId === null) {
                throw new Error(
                    'useApproveCompensationAssignmentMutation executed without a ready Membership context.',
                );
            }

            const result =
                await executeBrowserApiRequest(
                    apiClient.POST(
                        '/api/v1/hr/employments/{employmentId}/compensation-assignments/{assignmentId}/approve',
                        {
                            params: {
                                path: {
                                    employmentId,
                                    assignmentId,
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
                    'Compensation Assignment approval response was empty.',
                );
            }

            return result.data.data;
        },

        onSuccess: () => {
            invalidate(
                employmentId,
            );
        },
    });
}

export interface EndCompensationAssignmentInput {
    readonly assignmentId: string;
    readonly endDate: string;
}

export function useEndCompensationAssignmentMutation(
    employmentId: string,
): UseMutationResult<
    CompensationAssignmentResource,
    BrowserApiFailure,
    EndCompensationAssignmentInput
> {
    const apiClient =
        useApiClient();

    const membershipId =
        useMembershipId();

    const invalidate =
        useInvalidateAssignments();

    return useMutation<
        CompensationAssignmentResource,
        BrowserApiFailure,
        EndCompensationAssignmentInput
    >({
        mutationFn: async (
            input,
        ) => {
            if (membershipId === null) {
                throw new Error(
                    'useEndCompensationAssignmentMutation executed without a ready Membership context.',
                );
            }

            const result =
                await executeBrowserApiRequest(
                    apiClient.POST(
                        '/api/v1/hr/employments/{employmentId}/compensation-assignments/{assignmentId}/end',
                        {
                            params: {
                                path: {
                                    employmentId,

                                    assignmentId:
                                        input.assignmentId,
                                },

                                header:
                                    createBrowserMembershipHeaderParams(
                                        {
                                            membershipId,
                                        },
                                    ),
                            },

                            body: {
                                end_date:
                                    input.endDate,
                            },
                        },
                    ),
                );

            if (! result.ok) {
                throw result;
            }

            if (result.data === undefined) {
                throw new Error(
                    'Compensation Assignment end response was empty.',
                );
            }

            return result.data.data;
        },

        onSuccess: () => {
            invalidate(
                employmentId,
            );
        },
    });
}

export interface CorrectCompensationAssignmentInput {
    readonly assignmentId: string;
    readonly data: CompensationAssignmentDraftInput;
}

export function useCorrectCompensationAssignmentMutation(
    employmentId: string,
): UseMutationResult<
    CompensationAssignmentResource,
    BrowserApiFailure,
    CorrectCompensationAssignmentInput
> {
    const apiClient =
        useApiClient();

    const membershipId =
        useMembershipId();

    const invalidate =
        useInvalidateAssignments();

    return useMutation<
        CompensationAssignmentResource,
        BrowserApiFailure,
        CorrectCompensationAssignmentInput
    >({
        mutationFn: async (
            input,
        ) => {
            if (membershipId === null) {
                throw new Error(
                    'useCorrectCompensationAssignmentMutation executed without a ready Membership context.',
                );
            }

            const result =
                await executeBrowserApiRequest(
                    apiClient.POST(
                        '/api/v1/hr/employments/{employmentId}/compensation-assignments/{assignmentId}/correct',
                        {
                            params: {
                                path: {
                                    employmentId,

                                    assignmentId:
                                        input.assignmentId,
                                },

                                header:
                                    createBrowserMembershipHeaderParams(
                                        {
                                            membershipId,
                                        },
                                    ),
                            },

                            body:
                                input.data,
                        },
                    ),
                );

            if (! result.ok) {
                throw result;
            }

            if (result.data === undefined) {
                throw new Error(
                    'Compensation Assignment correction response was empty.',
                );
            }

            return result.data.data;
        },

        onSuccess: () => {
            invalidate(
                employmentId,
            );
        },
    });
}
