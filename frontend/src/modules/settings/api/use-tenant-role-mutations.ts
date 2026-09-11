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
    tenantRolesQueryKey,
    type TenantCustomRoleDetail,
} from '@/modules/settings/api/use-tenant-roles-query';

export type StoreTenantRoleInput =
    ApiComponents['schemas']['StoreTenantRoleRequest'];

function useMembershipId(): string | null {
    const workspaceState =
        useWorkspaceContextState();

    return workspaceState.status === 'ready'
        ? workspaceState.context.membership.id
        : null;
}

export function useCreateTenantRoleMutation(): UseMutationResult<
    TenantCustomRoleDetail,
    BrowserApiFailure,
    StoreTenantRoleInput
> {
    const apiClient =
        useApiClient();

    const membershipId =
        useMembershipId();

    const queryClient =
        useQueryClient();

    return useMutation<
        TenantCustomRoleDetail,
        BrowserApiFailure,
        StoreTenantRoleInput
    >({
        mutationFn: async (
            input,
        ) => {
            if (membershipId === null) {
                throw new Error(
                    'useCreateTenantRoleMutation executed without a ready Membership context.',
                );
            }

            const result =
                await executeBrowserApiRequest(
                    apiClient.POST(
                        '/api/v1/core/tenant-roles',
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
                    'Create tenant role response was empty.',
                );
            }

            return result.data.data;
        },

        onSuccess: () => {
            void queryClient.invalidateQueries(
                {
                    queryKey:
                        tenantRolesQueryKey,
                },
            );
        },
    });
}

export interface UpdateTenantRolePermissionsInput {
    readonly roleId: string;
    readonly permissionIds: readonly string[];
}

export function useUpdateTenantRolePermissionsMutation(): UseMutationResult<
    TenantCustomRoleDetail,
    BrowserApiFailure,
    UpdateTenantRolePermissionsInput
> {
    const apiClient =
        useApiClient();

    const membershipId =
        useMembershipId();

    const queryClient =
        useQueryClient();

    return useMutation<
        TenantCustomRoleDetail,
        BrowserApiFailure,
        UpdateTenantRolePermissionsInput
    >({
        mutationFn: async ({
            roleId,
            permissionIds,
        }) => {
            if (membershipId === null) {
                throw new Error(
                    'useUpdateTenantRolePermissionsMutation executed without a ready Membership context.',
                );
            }

            const result =
                await executeBrowserApiRequest(
                    apiClient.PUT(
                        '/api/v1/core/tenant-roles/{roleId}',
                        {
                            params: {
                                path: {
                                    roleId,
                                },

                                header:
                                    createBrowserMembershipHeaderParams(
                                        {
                                            membershipId,
                                        },
                                    ),
                            },

                            body: {
                                permission_ids:
                                    [...permissionIds],
                            },
                        },
                    ),
                );

            if (! result.ok) {
                throw result;
            }

            if (result.data === undefined) {
                throw new Error(
                    'Update tenant role permissions response was empty.',
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
                        tenantRolesQueryKey,
                },
            );

            void queryClient.invalidateQueries(
                {
                    queryKey: [
                        ...tenantRolesQueryKey,
                        variables.roleId,
                    ],
                },
            );
        },
    });
}
