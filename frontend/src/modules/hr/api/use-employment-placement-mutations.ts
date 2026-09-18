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
    employmentPlacementsQueryKey,
    type EmploymentPlacementResource,
} from '@/modules/hr/api/use-employment-placements-query';
import {
    executeBrowserApiRequest,
    type BrowserApiFailure,
} from '@/platform/api';
import {
    createBrowserWorkspaceHeaderParams,
} from '@/platform/api/request-context';

export interface CreatePlacementInput {
    readonly employmentId: string;
    readonly organizationalAssignmentId: string;
    readonly effectiveFrom: string;
    readonly isPrimary: boolean;
}

function useWorkspaceHeaderInputs(): {
    membershipId: string | null;
    organizationalAssignmentId: string | null;
} {
    const workspaceState =
        useWorkspaceContextState();

    const isReady =
        workspaceState.status === 'ready';

    return {
        membershipId:
            isReady
                ? workspaceState.context.membership.id
                : null,

        organizationalAssignmentId:
            isReady
            && workspaceState.current.organizational_assignment_id !== null
                ? workspaceState.current.organizational_assignment_id
                : null,
    };
}

export function useCreatePlacementMutation(): UseMutationResult<
    EmploymentPlacementResource,
    BrowserApiFailure,
    CreatePlacementInput
> {
    const apiClient =
        useApiClient();

    const {
        membershipId,
        organizationalAssignmentId,
    } =
        useWorkspaceHeaderInputs();

    const queryClient =
        useQueryClient();

    return useMutation<
        EmploymentPlacementResource,
        BrowserApiFailure,
        CreatePlacementInput
    >({
        mutationFn: async (
            input,
        ) => {
            if (
                membershipId === null
                || organizationalAssignmentId === null
            ) {
                throw new Error(
                    'useCreatePlacementMutation executed without a ready organizational workspace.',
                );
            }

            const result =
                await executeBrowserApiRequest(
                    apiClient.POST(
                        '/api/v1/hr/workspace/employments/{employmentId}/placements',
                        {
                            params: {
                                path: {
                                    employmentId:
                                        input.employmentId,
                                },

                                header:
                                    createBrowserWorkspaceHeaderParams(
                                        {
                                            membershipId,
                                            organizationalAssignmentId,
                                        },
                                    ),
                            },

                            body: {
                                organizational_assignment_id:
                                    input.organizationalAssignmentId,

                                effective_from:
                                    input.effectiveFrom,

                                is_primary:
                                    input.isPrimary,
                            },
                        },
                    ),
                );

            if (! result.ok) {
                throw result;
            }

            if (result.data === undefined) {
                throw new Error(
                    'Placement creation response was empty.',
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
                        employmentPlacementsQueryKey(
                            variables.employmentId,
                        ),
                },
            );
        },
    });
}
