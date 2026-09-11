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
    ApiComponents,
} from '@/platform/api';
import {
    executeBrowserApiRequest,
    type BrowserApiFailure,
} from '@/platform/api';
import {
    createBrowserMembershipHeaderParams,
} from '@/platform/api/request-context';
import {
    organizationalAssignmentsQueryKey,
    type OrganizationalAssignmentResource,
} from '@/modules/settings/organizations/api/use-organizational-assignments-query';

export type StoreOrganizationalAssignmentInput =
    ApiComponents['schemas']['StoreOrganizationalAssignmentRequest'];

function useMembershipId(): string | null {
    const workspaceState =
        useWorkspaceContextState();

    return workspaceState.status === 'ready'
        ? workspaceState.context.membership.id
        : null;
}

export function useCreateOrganizationalAssignmentMutation(
    organizationId:
        string,
): UseMutationResult<
    OrganizationalAssignmentResource,
    BrowserApiFailure,
    StoreOrganizationalAssignmentInput
> {
    const apiClient =
        useApiClient();

    const membershipId =
        useMembershipId();

    const queryClient =
        useQueryClient();

    return useMutation<
        OrganizationalAssignmentResource,
        BrowserApiFailure,
        StoreOrganizationalAssignmentInput
    >({
        mutationFn: async (
            input,
        ) => {
            if (membershipId === null) {
                throw new Error(
                    'useCreateOrganizationalAssignmentMutation executed without a ready Membership context.',
                );
            }

            const result =
                await executeBrowserApiRequest(
                    apiClient.POST(
                        '/api/v1/core/organizations/{organization}/assignments',
                        {
                            params: {
                                path: {
                                    organization:
                                        organizationId,
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
                    'Create organizational assignment response was empty.',
                );
            }

            return result.data.data;
        },

        onSuccess: () => {
            void queryClient.invalidateQueries(
                {
                    queryKey:
                        organizationalAssignmentsQueryKey(
                            organizationId,
                        ),
                },
            );
        },
    });
}

export function useDeactivateOrganizationalAssignmentMutation(
    organizationId:
        string,
): UseMutationResult<
    OrganizationalAssignmentResource,
    BrowserApiFailure,
    /*
     * Mutation variable is the assignment id being deactivated —
     * named as a small object rather than a bare string so a call
     * site (`mutate({ assignmentId })`) stays self-describing.
     */
    { assignmentId: string }
> {
    const apiClient =
        useApiClient();

    const membershipId =
        useMembershipId();

    const queryClient =
        useQueryClient();

    return useMutation<
        OrganizationalAssignmentResource,
        BrowserApiFailure,
        { assignmentId: string }
    >({
        mutationFn: async (
            {
                assignmentId,
            },
        ) => {
            if (membershipId === null) {
                throw new Error(
                    'useDeactivateOrganizationalAssignmentMutation executed without a ready Membership context.',
                );
            }

            const result =
                await executeBrowserApiRequest(
                    apiClient.POST(
                        '/api/v1/core/organizations/{organization}/assignments/{assignment}/deactivate',
                        {
                            params: {
                                path: {
                                    organization:
                                        organizationId,

                                    assignment:
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
                    'Deactivate organizational assignment response was empty.',
                );
            }

            return result.data.data;
        },

        onSuccess: () => {
            void queryClient.invalidateQueries(
                {
                    queryKey:
                        organizationalAssignmentsQueryKey(
                            organizationId,
                        ),
                },
            );
        },
    });
}
