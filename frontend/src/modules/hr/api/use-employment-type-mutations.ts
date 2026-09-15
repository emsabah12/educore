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
    employmentTypesQueryKey,
    type EmploymentTypeResource,
} from '@/modules/hr/api/use-employment-types-query';
import {
    executeBrowserApiRequest,
    type BrowserApiFailure,
} from '@/platform/api';
import {
    createBrowserMembershipHeaderParams,
} from '@/platform/api/request-context';

export interface CreateEmploymentTypeInput {
    readonly code: string;
    readonly name: string;
    readonly description: string | null;
}

/*
 * Employment Type katalog TENANT-wide (sama seperti
 * useEmploymentTypesQuery yang sudah ada) — bukan Organizational
 * Workspace scope, cukup Membership context.
 */
function useMembershipId(): string | null {
    const workspaceState =
        useWorkspaceContextState();

    return workspaceState.status === 'ready'
        ? workspaceState.context.membership.id
        : null;
}

export function useCreateEmploymentTypeMutation(): UseMutationResult<
    EmploymentTypeResource,
    BrowserApiFailure,
    CreateEmploymentTypeInput
> {
    const apiClient =
        useApiClient();

    const membershipId =
        useMembershipId();

    const queryClient =
        useQueryClient();

    return useMutation<
        EmploymentTypeResource,
        BrowserApiFailure,
        CreateEmploymentTypeInput
    >({
        mutationFn: async (
            input,
        ) => {
            if (membershipId === null) {
                throw new Error(
                    'useCreateEmploymentTypeMutation executed without a ready Membership context.',
                );
            }

            const result =
                await executeBrowserApiRequest(
                    apiClient.POST(
                        '/api/v1/hr/employment-types',
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
                    'Employment Type creation response was empty.',
                );
            }

            return result.data.data;
        },

        onSuccess: () => {
            void queryClient.invalidateQueries(
                {
                    queryKey:
                        employmentTypesQueryKey,
                },
            );
        },
    });
}
