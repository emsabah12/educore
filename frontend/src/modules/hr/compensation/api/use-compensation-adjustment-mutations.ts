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
    compensationAdjustmentsQueryKey,
    type CompensationAdjustmentResource,
} from '@/modules/hr/compensation/api/use-compensation-adjustments-query';
import {
    executeBrowserApiRequest,
    type BrowserApiFailure,
} from '@/platform/api';
import {
    createBrowserMembershipHeaderParams,
} from '@/platform/api/request-context';

export interface CompensationAdjustmentDraftInput {
    readonly compensation_component_id: string | null;
    readonly adjustment_type: CompensationAdjustmentResource['adjustment_type'];
    readonly amount: number;
    readonly currency_code: string;
    readonly target_period_start: string;
    readonly target_period_end: string;
    readonly reason: string;
    readonly idempotency_key: string;
}

function useMembershipId(): string | null {
    const workspaceState =
        useWorkspaceContextState();

    return workspaceState.status === 'ready'
        ? workspaceState.context.membership.id
        : null;
}

function useInvalidateAdjustments() {
    const queryClient =
        useQueryClient();

    return (
        employmentId: string,
    ) => {
        void queryClient.invalidateQueries(
            {
                queryKey:
                    compensationAdjustmentsQueryKey(
                        employmentId,
                    ),
            },
        );
    };
}

export function useCreateCompensationAdjustmentMutation(
    employmentId: string,
): UseMutationResult<
    CompensationAdjustmentResource,
    BrowserApiFailure,
    CompensationAdjustmentDraftInput
> {
    const apiClient =
        useApiClient();

    const membershipId =
        useMembershipId();

    const invalidate =
        useInvalidateAdjustments();

    return useMutation<
        CompensationAdjustmentResource,
        BrowserApiFailure,
        CompensationAdjustmentDraftInput
    >({
        mutationFn: async (
            input,
        ) => {
            if (membershipId === null) {
                throw new Error(
                    'useCreateCompensationAdjustmentMutation executed without a ready Membership context.',
                );
            }

            const result =
                await executeBrowserApiRequest(
                    apiClient.POST(
                        '/api/v1/hr/employments/{employmentId}/compensation-adjustments',
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
                    'Compensation Adjustment draft creation response was empty.',
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

type AdjustmentTransitionMutation = UseMutationResult<
    CompensationAdjustmentResource,
    BrowserApiFailure,
    string
>;

function useAdjustmentTransitionMutation(
    employmentId: string,
    path:
        | '/api/v1/hr/employments/{employmentId}/compensation-adjustments/{adjustmentId}/submit'
        | '/api/v1/hr/employments/{employmentId}/compensation-adjustments/{adjustmentId}/cancel'
        | '/api/v1/hr/employments/{employmentId}/compensation-adjustments/{adjustmentId}/approve'
        | '/api/v1/hr/employments/{employmentId}/compensation-adjustments/{adjustmentId}/reject',
    errorMessage: string,
): AdjustmentTransitionMutation {
    const apiClient =
        useApiClient();

    const membershipId =
        useMembershipId();

    const invalidate =
        useInvalidateAdjustments();

    return useMutation<
        CompensationAdjustmentResource,
        BrowserApiFailure,
        string
    >({
        mutationFn: async (
            adjustmentId,
        ) => {
            if (membershipId === null) {
                throw new Error(
                    errorMessage,
                );
            }

            const result =
                await executeBrowserApiRequest(
                    apiClient.POST(
                        path,
                        {
                            params: {
                                path: {
                                    employmentId,
                                    adjustmentId,
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
                    'Compensation Adjustment transition response was empty.',
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

export function useSubmitCompensationAdjustmentMutation(
    employmentId: string,
): AdjustmentTransitionMutation {
    return useAdjustmentTransitionMutation(
        employmentId,
        '/api/v1/hr/employments/{employmentId}/compensation-adjustments/{adjustmentId}/submit',
        'useSubmitCompensationAdjustmentMutation executed without a ready Membership context.',
    );
}

export function useCancelCompensationAdjustmentMutation(
    employmentId: string,
): AdjustmentTransitionMutation {
    return useAdjustmentTransitionMutation(
        employmentId,
        '/api/v1/hr/employments/{employmentId}/compensation-adjustments/{adjustmentId}/cancel',
        'useCancelCompensationAdjustmentMutation executed without a ready Membership context.',
    );
}

export function useApproveCompensationAdjustmentMutation(
    employmentId: string,
): AdjustmentTransitionMutation {
    return useAdjustmentTransitionMutation(
        employmentId,
        '/api/v1/hr/employments/{employmentId}/compensation-adjustments/{adjustmentId}/approve',
        'useApproveCompensationAdjustmentMutation executed without a ready Membership context.',
    );
}

export function useRejectCompensationAdjustmentMutation(
    employmentId: string,
): AdjustmentTransitionMutation {
    return useAdjustmentTransitionMutation(
        employmentId,
        '/api/v1/hr/employments/{employmentId}/compensation-adjustments/{adjustmentId}/reject',
        'useRejectCompensationAdjustmentMutation executed without a ready Membership context.',
    );
}
