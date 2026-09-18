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
    employmentPositionAssignmentsQueryKey,
    type EmploymentPositionAssignmentResource,
} from '@/modules/hr/api/use-employment-position-assignments-query';
import {
    executeBrowserApiRequest,
    type BrowserApiFailure,
} from '@/platform/api';
import {
    createBrowserWorkspaceHeaderParams,
} from '@/platform/api/request-context';

export interface CreatePositionAssignmentInput {
    readonly employmentId: string;
    readonly positionId: string;
    readonly employmentPlacementId: string | null;
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

export function useCreatePositionAssignmentMutation(): UseMutationResult<
    EmploymentPositionAssignmentResource,
    BrowserApiFailure,
    CreatePositionAssignmentInput
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
        EmploymentPositionAssignmentResource,
        BrowserApiFailure,
        CreatePositionAssignmentInput
    >({
        mutationFn: async (
            input,
        ) => {
            if (
                membershipId === null
                || organizationalAssignmentId === null
            ) {
                throw new Error(
                    'useCreatePositionAssignmentMutation executed without a ready organizational workspace.',
                );
            }

            const result =
                await executeBrowserApiRequest(
                    apiClient.POST(
                        '/api/v1/hr/workspace/employments/{employmentId}/position-assignments',
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
                                position_id:
                                    input.positionId,

                                employment_placement_id:
                                    input.employmentPlacementId,

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
                    'Position Assignment creation response was empty.',
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
                        employmentPositionAssignmentsQueryKey(
                            variables.employmentId,
                        ),
                },
            );
        },
    });
}
