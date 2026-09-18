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
    leaveEntitlementPoliciesQueryKey,
    type LeaveEntitlementPolicyResource,
} from '@/modules/hr/api/use-leave-entitlement-policies-query';
import {
    executeBrowserApiRequest,
    type BrowserApiFailure,
} from '@/platform/api';
import {
    createBrowserMembershipHeaderParams,
} from '@/platform/api/request-context';

export interface CreateLeaveEntitlementPolicyInput {
    readonly leave_type_id: string;
    readonly period_basis: LeaveEntitlementPolicyResource['period_basis'];
    readonly grant_units: number;
    readonly carryover_mode: LeaveEntitlementPolicyResource['carryover_mode'];
    readonly carryover_limit_units: number | null;
    readonly effective_from: string;
    readonly effective_to: string | null;
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
                    leaveEntitlementPoliciesQueryKey,
            },
        );
    };
}

export function useCreateLeaveEntitlementPolicyMutation(): UseMutationResult<
    LeaveEntitlementPolicyResource,
    BrowserApiFailure,
    CreateLeaveEntitlementPolicyInput
> {
    const apiClient =
        useApiClient();

    const membershipId =
        useMembershipId();

    const invalidate =
        useInvalidatePolicies();

    return useMutation<
        LeaveEntitlementPolicyResource,
        BrowserApiFailure,
        CreateLeaveEntitlementPolicyInput
    >({
        mutationFn: async (
            input,
        ) => {
            if (membershipId === null) {
                throw new Error(
                    'useCreateLeaveEntitlementPolicyMutation executed without a ready Membership context.',
                );
            }

            const result =
                await executeBrowserApiRequest(
                    apiClient.POST(
                        '/api/v1/hr/leave-entitlement-policies',
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
                            },
                        },
                    ),
                );

            if (! result.ok) {
                throw result;
            }

            if (result.data === undefined) {
                throw new Error(
                    'LeaveEntitlementPolicy creation response was empty.',
                );
            }

            return result.data.data;
        },

        onSuccess: () => {
            invalidate();
        },
    });
}

export function useDeactivateLeaveEntitlementPolicyMutation(): UseMutationResult<
    LeaveEntitlementPolicyResource,
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
        LeaveEntitlementPolicyResource,
        BrowserApiFailure,
        string
    >({
        mutationFn: async (
            entitlementPolicyId,
        ) => {
            if (membershipId === null) {
                throw new Error(
                    'useDeactivateLeaveEntitlementPolicyMutation executed without a ready Membership context.',
                );
            }

            const result =
                await executeBrowserApiRequest(
                    apiClient.POST(
                        '/api/v1/hr/leave-entitlement-policies/{entitlementPolicyId}/deactivate',
                        {
                            params: {
                                path: {
                                    entitlementPolicyId,
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
                    'LeaveEntitlementPolicy deactivation response was empty.',
                );
            }

            return result.data.data;
        },

        onSuccess: () => {
            invalidate();
        },
    });
}
