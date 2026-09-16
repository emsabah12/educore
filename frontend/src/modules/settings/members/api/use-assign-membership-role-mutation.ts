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
    tenantMembershipsQueryKey,
} from '@/modules/settings/members/api/use-tenant-memberships-query';
import {
    executeBrowserApiRequest,
    type BrowserApiFailure,
} from '@/platform/api';
import {
    createBrowserMembershipHeaderParams,
} from '@/platform/api/request-context';

export interface AssignMembershipRoleInput {
    readonly targetMembershipId: string;
    readonly roleId: string;
}

function useMembershipId(): string | null {
    const workspaceState =
        useWorkspaceContextState();

    return workspaceState.status === 'ready'
        ? workspaceState.context.membership.id
        : null;
}

export function useAssignMembershipRoleMutation(): UseMutationResult<
    void,
    BrowserApiFailure,
    AssignMembershipRoleInput
> {
    const apiClient =
        useApiClient();

    const membershipId =
        useMembershipId();

    const queryClient =
        useQueryClient();

    return useMutation<
        void,
        BrowserApiFailure,
        AssignMembershipRoleInput
    >({
        mutationFn: async (
            input,
        ) => {
            if (membershipId === null) {
                throw new Error(
                    'useAssignMembershipRoleMutation executed without a ready Membership context.',
                );
            }

            const result =
                await executeBrowserApiRequest(
                    apiClient.POST(
                        '/api/v1/user/memberships/{target_membership_id}/assign-role',
                        {
                            params: {
                                path: {
                                    target_membership_id:
                                        input.targetMembershipId,
                                },

                                header:
                                    createBrowserMembershipHeaderParams(
                                        {
                                            membershipId,
                                        },
                                    ),
                            },

                            body: {
                                role_id:
                                    input.roleId,
                            },
                        },
                    ),
                );

            if (! result.ok) {
                throw result;
            }
        },

        onSuccess: () => {
            void queryClient.invalidateQueries(
                {
                    queryKey:
                        tenantMembershipsQueryKey,
                },
            );
        },
    });
}
