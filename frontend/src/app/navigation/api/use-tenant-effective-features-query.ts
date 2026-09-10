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
import {
    executeBrowserApiReadRequest,
    type BrowserApiFailure,
} from '@/platform/api';
import {
    createBrowserMembershipHeaderParams,
} from '@/platform/api/request-context';

/*
 * Tenant-wide (same pattern as useTenantRolesQuery) — only a
 * ready Membership context is required, no organizational
 * Workspace selection.
 */
function useMembershipId(): string | null {
    const workspaceState =
        useWorkspaceContextState();

    return workspaceState.status === 'ready'
        ? workspaceState.context.membership.id
        : null;
}

export const tenantEffectiveFeaturesQueryKey = [
    'core',
    'tenant-subscription',
    'effective-features',
] as const;

/*
 * Navigation visibility fails CLOSED while this query has not
 * yet resolved — callers should treat `undefined`/pending data
 * the same as "no features available" rather than optimistically
 * showing feature-gated destinations before this loads.
 */
export function useTenantEffectiveFeaturesQuery(): UseQueryResult<readonly string[],
    BrowserApiFailure
> {
    const apiClient =
        useApiClient();

    const membershipId =
        useMembershipId();

    return useQuery<readonly string[],
        BrowserApiFailure
    >({
        queryKey:
            tenantEffectiveFeaturesQueryKey,

        enabled:
            membershipId !== null,

        queryFn: async () => {
            if (membershipId === null) {
                throw new Error(
                    'useTenantEffectiveFeaturesQuery executed without a ready Membership context.',
                );
            }

            const result =
                await executeBrowserApiReadRequest(
                    () =>
                        apiClient.GET(
                            '/api/v1/core/tenant-subscription/effective-features',
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

            return result.data?.data.feature_codes ?? [];
        },
    });
}
