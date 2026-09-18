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
    leaveApprovalPoliciesQueryKey,
    type LeaveApprovalPolicyResource,
    type LeaveApprovalPolicyStepResource,
} from '@/modules/hr/api/use-leave-approval-policies-query';
import {
    executeBrowserApiRequest,
    type BrowserApiFailure,
} from '@/platform/api';
import {
    createBrowserMembershipHeaderParams,
} from '@/platform/api/request-context';

export interface CreateLeaveApprovalPolicyStepInput {
    readonly step_order: number;
    readonly required_permission: string;
    readonly scope_strategy: LeaveApprovalPolicyStepResource['scope_strategy'];
    readonly independent_approver: boolean;
}

export interface CreateLeaveApprovalPolicyInput {
    readonly policy_code: string;
    readonly name: string;
    readonly leave_type_id: string | null;
    readonly decision_mode: LeaveApprovalPolicyResource['decision_mode'];
    readonly effective_from: string;
    readonly effective_to: string | null;
    readonly steps: readonly CreateLeaveApprovalPolicyStepInput[];
}

function useMembershipId(): string | null {
    const workspaceState =
        useWorkspaceContextState();

    return workspaceState.status === 'ready'
        ? workspaceState.context.membership.id
        : null;
}

function useInvalidatePolicies() {
    const queryClient =
        useQueryClient();

    return () => {
        void queryClient.invalidateQueries(
            {
                queryKey:
                    leaveApprovalPoliciesQueryKey,
            },
        );
    };
}

export function useCreateLeaveApprovalPolicyMutation(): UseMutationResult<
    LeaveApprovalPolicyResource,
    BrowserApiFailure,
    CreateLeaveApprovalPolicyInput
> {
    const apiClient =
        useApiClient();

    const membershipId =
        useMembershipId();

    const invalidate =
        useInvalidatePolicies();

    return useMutation<
        LeaveApprovalPolicyResource,
        BrowserApiFailure,
        CreateLeaveApprovalPolicyInput
    >({
        mutationFn: async (
            input,
        ) => {
            if (membershipId === null) {
                throw new Error(
                    'useCreateLeaveApprovalPolicyMutation executed without a ready Membership context.',
                );
            }

            const result =
                await executeBrowserApiRequest(
                    apiClient.POST(
                        '/api/v1/hr/leave-approval-policies',
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
                                ...input,

                                organization_id: null,
                                organization_unit_id: null,
                                employment_type_id: null,
                                employment_classification_id: null,

                                steps:
                                    input.steps.map(
                                        (
                                            step,
                                        ) => (
                                            {
                                                ...step,
                                            }
                                        ),
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
                    'LeaveApprovalPolicy creation response was empty.',
                );
            }

            return result.data.data;
        },

        onSuccess: () => {
            invalidate();
        },
    });
}

export function useDeactivateLeaveApprovalPolicyMutation(): UseMutationResult<
    LeaveApprovalPolicyResource,
    BrowserApiFailure,
    string
> {
    const apiClient =
        useApiClient();

    const membershipId =
        useMembershipId();

    const invalidate =
        useInvalidatePolicies();

    return useMutation<
        LeaveApprovalPolicyResource,
        BrowserApiFailure,
        string
    >({
        mutationFn: async (
            approvalPolicyId,
        ) => {
            if (membershipId === null) {
                throw new Error(
                    'useDeactivateLeaveApprovalPolicyMutation executed without a ready Membership context.',
                );
            }

            const result =
                await executeBrowserApiRequest(
                    apiClient.POST(
                        '/api/v1/hr/leave-approval-policies/{approvalPolicyId}/deactivate',
                        {
                            params: {
                                path: {
                                    approvalPolicyId,
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
                    'LeaveApprovalPolicy deactivation response was empty.',
                );
            }

            return result.data.data;
        },

        onSuccess: () => {
            invalidate();
        },
    });
}
