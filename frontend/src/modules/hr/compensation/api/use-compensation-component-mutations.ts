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
    compensationComponentsQueryKey,
    type CompensationComponentResource,
} from '@/modules/hr/compensation/api/use-compensation-components-query';
import {
    executeBrowserApiRequest,
    type BrowserApiFailure,
} from '@/platform/api';
import {
    createBrowserMembershipHeaderParams,
} from '@/platform/api/request-context';

export interface CreateCompensationComponentInput {
    readonly code: string;
    readonly name: string;
    readonly category: CompensationComponentResource['category'];
    readonly value_mode: CompensationComponentResource['value_mode'];
    readonly unit_code: string | null;
    readonly periodicity: CompensationComponentResource['periodicity'];
    readonly description: string | null;
}

function useMembershipId(): string | null {
    const workspaceState =
        useWorkspaceContextState();

    return workspaceState.status === 'ready'
        ? workspaceState.context.membership.id
        : null;
}

export function useCreateCompensationComponentMutation(): UseMutationResult<
    CompensationComponentResource,
    BrowserApiFailure,
    CreateCompensationComponentInput
> {
    const apiClient =
        useApiClient();

    const membershipId =
        useMembershipId();

    const queryClient =
        useQueryClient();

    return useMutation<
        CompensationComponentResource,
        BrowserApiFailure,
        CreateCompensationComponentInput
    >({
        mutationFn: async (
            input,
        ) => {
            if (membershipId === null) {
                throw new Error(
                    'useCreateCompensationComponentMutation executed without a ready Membership context.',
                );
            }

            const result =
                await executeBrowserApiRequest(
                    apiClient.POST(
                        '/api/v1/hr/compensation/components',
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
                    'Compensation Component creation response was empty.',
                );
            }

            return result.data.data;
        },

        onSuccess: () => {
            void queryClient.invalidateQueries(
                {
                    queryKey:
                        compensationComponentsQueryKey,
                },
            );
        },
    });
}
