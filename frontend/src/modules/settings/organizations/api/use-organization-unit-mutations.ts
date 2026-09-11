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
    organizationUnitsQueryKey,
    type OrganizationUnitResource,
} from '@/modules/settings/organizations/api/use-organization-units-query';

export type StoreOrganizationUnitInput =
    ApiComponents['schemas']['StoreOrganizationUnitRequest'];

function useMembershipId(): string | null {
    const workspaceState =
        useWorkspaceContextState();

    return workspaceState.status === 'ready'
        ? workspaceState.context.membership.id
        : null;
}

export function useCreateOrganizationUnitMutation(
    organizationId:
        string,
): UseMutationResult<
    OrganizationUnitResource,
    BrowserApiFailure,
    StoreOrganizationUnitInput
> {
    const apiClient =
        useApiClient();

    const membershipId =
        useMembershipId();

    const queryClient =
        useQueryClient();

    return useMutation<
        OrganizationUnitResource,
        BrowserApiFailure,
        StoreOrganizationUnitInput
    >({
        mutationFn: async (
            input,
        ) => {
            if (membershipId === null) {
                throw new Error(
                    'useCreateOrganizationUnitMutation executed without a ready Membership context.',
                );
            }

            const result =
                await executeBrowserApiRequest(
                    apiClient.POST(
                        '/api/v1/core/organizations/{organization}/units',
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
                    'Create organization unit response was empty.',
                );
            }

            return result.data.data;
        },

        onSuccess: () => {
            void queryClient.invalidateQueries(
                {
                    queryKey:
                        organizationUnitsQueryKey(
                            organizationId,
                        ),
                },
            );
        },
    });
}
