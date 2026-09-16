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

export type RoleSummary =
    ApiComponents['schemas']['RoleSummary'];

function useMembershipId(): string | null {
    const workspaceState =
        useWorkspaceContextState();

    return workspaceState.status === 'ready'
        ? workspaceState.context.membership.id
        : null;
}

export const roleCatalogQueryKey = [
    'settings',
    'role-catalog',
] as const;

/*
 * §Kelola Anggota & Role — HANYA role GLOBAL (tenant_id NULL),
 * SENGAJA TIDAK termasuk role kustom milik tenant lain. Role
 * kustom milik tenant SAAT INI diambil terpisah lewat
 * useTenantRolesQuery yang sudah ada (settings/api) — gabungkan
 * keduanya di komponen, jangan gabungkan query-nya, supaya cache
 * invalidation masing-masing tetap independen dan benar.
 */
export function useRoleCatalogQuery(): UseQueryResult<
    readonly RoleSummary[],
    BrowserApiFailure
> {
    const apiClient =
        useApiClient();

    const membershipId =
        useMembershipId();

    return useQuery<
        readonly RoleSummary[],
        BrowserApiFailure
    >({
        queryKey:
            roleCatalogQueryKey,

        enabled:
            membershipId !== null,

        queryFn: async () => {
            if (membershipId === null) {
                throw new Error(
                    'useRoleCatalogQuery executed without a ready Membership context.',
                );
            }

            const result =
                await executeBrowserApiReadRequest(
                    () =>
                        apiClient.GET(
                            '/api/v1/core/authorization/roles',
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
