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

export type TenantMembershipSummary =
    ApiComponents['schemas']['TenantMembershipSummary'];

/*
 * §Kelola Anggota & Role — tenant-wide (bukan Organizational
 * Workspace scope), cukup Membership context. Endpoint ini
 * digerbang tenant.role:admin di backend — halaman ini sendiri
 * hanya bisa dijangkau lewat route policy mode 'tenant-admin'
 * (lihat settings/routes.ts).
 */
function useMembershipId(): string | null {
    const workspaceState =
        useWorkspaceContextState();

    return workspaceState.status === 'ready'
        ? workspaceState.context.membership.id
        : null;
}

export const tenantMembershipsQueryKey = [
    'settings',
    'tenant-memberships',
] as const;

export function useTenantMembershipsQuery(): UseQueryResult<
    readonly TenantMembershipSummary[],
    BrowserApiFailure
> {
    const apiClient =
        useApiClient();

    const membershipId =
        useMembershipId();

    return useQuery<
        readonly TenantMembershipSummary[],
        BrowserApiFailure
    >({
        queryKey:
            tenantMembershipsQueryKey,

        enabled:
            membershipId !== null,

        queryFn: async () => {
            if (membershipId === null) {
                throw new Error(
                    'useTenantMembershipsQuery executed without a ready Membership context.',
                );
            }

            const result =
                await executeBrowserApiReadRequest(
                    () =>
                        apiClient.GET(
                            '/api/v1/user/tenant-memberships',
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
