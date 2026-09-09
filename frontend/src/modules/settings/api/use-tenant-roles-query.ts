import {
    useQuery,
    type UseQueryResult,
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
    executeBrowserApiReadRequest,
    type BrowserApiFailure,
} from '@/platform/api';
import {
    createBrowserMembershipHeaderParams,
} from '@/platform/api/request-context';

export type TenantCustomRoleSummary =
    ApiComponents['schemas']['TenantCustomRoleSummary'];

export type TenantCustomRoleDetail =
    ApiComponents['schemas']['TenantCustomRoleDetail'];

export type TenantCustomRoleAssignablePermission =
    ApiComponents['schemas']['TenantCustomRoleAssignablePermission'];

/*
 * This feature is tenant-wide (HR-013/Step D custom_roles
 * feature is a tenant-level Subscription entitlement, not an
 * organizational one), so unlike the HR Workforce hooks this
 * only needs a ready Membership context — no organizational
 * Workspace selection is required.
 */
function useMembershipId(): string | null {
    const workspaceState =
        useWorkspaceContextState();

    return workspaceState.status === 'ready'
        ? workspaceState.context.membership.id
        : null;
}

export const tenantRolesQueryKey = [
    'settings',
    'tenant-roles',
] as const;

export function useTenantRolesQuery(): UseQueryResult<
    readonly TenantCustomRoleSummary[],
    BrowserApiFailure
> {
    const apiClient =
        useApiClient();

    const membershipId =
        useMembershipId();

    return useQuery<
        readonly TenantCustomRoleSummary[],
        BrowserApiFailure
    >({
        queryKey:
            tenantRolesQueryKey,

        enabled:
            membershipId !== null,

        queryFn: async () => {
            if (membershipId === null) {
                throw new Error(
                    'useTenantRolesQuery executed without a ready Membership context.',
                );
            }

            const result =
                await executeBrowserApiReadRequest(
                    () =>
                        apiClient.GET(
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
                            },
                        ),
                );

            if (! result.ok) {
                throw result;
            }

            return (
                result.data?.data
                ?? []
            );
        },
    });
}

export function useTenantRoleQuery(
    roleId: string | null,
): UseQueryResult<
    TenantCustomRoleDetail,
    BrowserApiFailure
> {
    const apiClient =
        useApiClient();

    const membershipId =
        useMembershipId();

    return useQuery<
        TenantCustomRoleDetail,
        BrowserApiFailure
    >({
        queryKey: [
            ...tenantRolesQueryKey,
            roleId,
        ],

        enabled:
            membershipId !== null
            && roleId !== null,

        queryFn: async () => {
            if (
                membershipId === null
                || roleId === null
            ) {
                throw new Error(
                    'useTenantRoleQuery executed without a ready Membership context or roleId.',
                );
            }

            const result =
                await executeBrowserApiReadRequest(
                    () =>
                        apiClient.GET(
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
                            },
                        ),
                );

            if (! result.ok) {
                throw result;
            }

            if (result.data === undefined) {
                throw new Error(
                    'Tenant role detail response was empty.',
                );
            }

            return result.data.data;
        },
    });
}

export function useAssignablePermissionsQuery(): UseQueryResult<
    readonly TenantCustomRoleAssignablePermission[],
    BrowserApiFailure
> {
    const apiClient =
        useApiClient();

    const membershipId =
        useMembershipId();

    return useQuery<
        readonly TenantCustomRoleAssignablePermission[],
        BrowserApiFailure
    >({
        queryKey: [
            'settings',
            'tenant-roles',
            'assignable-permissions',
        ],

        enabled:
            membershipId !== null,

        queryFn: async () => {
            if (membershipId === null) {
                throw new Error(
                    'useAssignablePermissionsQuery executed without a ready Membership context.',
                );
            }

            const result =
                await executeBrowserApiReadRequest(
                    () =>
                        apiClient.GET(
                            '/api/v1/core/tenant-roles/assignable-permissions',
                            {
                                params: {
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

            return (
                result.data?.data
                ?? []
            );
        },
    });
}