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
    positionsQueryKey,
    type PositionResource,
} from '@/modules/hr/api/use-positions-query';
import {
    executeBrowserApiRequest,
    type BrowserApiFailure,
} from '@/platform/api';
import {
    createBrowserMembershipHeaderParams,
} from '@/platform/api/request-context';

export interface CreatePositionInput {
    readonly code: string;
    readonly name: string;
    readonly description: string | null;
}

function useMembershipId(): string | null {
    const workspaceState =
        useWorkspaceContextState();

    return workspaceState.status === 'ready'
        ? workspaceState.context.membership.id
        : null;
}

export function useCreatePositionMutation(): UseMutationResult<
    PositionResource,
    BrowserApiFailure,
    CreatePositionInput
> {
    const apiClient =
        useApiClient();

    const membershipId =
        useMembershipId();

    const queryClient =
        useQueryClient();

    return useMutation<
        PositionResource,
        BrowserApiFailure,
        CreatePositionInput
    >({
        mutationFn: async (
            input,
        ) => {
            if (membershipId === null) {
                throw new Error(
                    'useCreatePositionMutation executed without a ready Membership context.',
                );
            }

            const result =
                await executeBrowserApiRequest(
                    apiClient.POST(
                        '/api/v1/hr/positions',
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
                    'Position creation response was empty.',
                );
            }

            return result.data.data;
        },

        onSuccess: () => {
            void queryClient.invalidateQueries(
                {
                    queryKey:
                        positionsQueryKey,
                },
            );
        },
    });
}
