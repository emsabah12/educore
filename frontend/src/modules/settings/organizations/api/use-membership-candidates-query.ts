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

export type MembershipCandidateResource =
    ApiComponents['schemas']['MembershipCandidateResource'];

function useMembershipId(): string | null {
    const workspaceState =
        useWorkspaceContextState();

    return workspaceState.status === 'ready'
        ? workspaceState.context.membership.id
        : null;
}

/*
 * `query` is expected to already be debounced by the caller — this
 * hook itself does not debounce, it only decides whether a query
 * this short is even worth sending (mirrors the backend's own <2
 * character contract, so the request simply never fires instead of
 * firing and getting an empty array back).
 */
export function useMembershipCandidatesQuery(
    organizationId:
        string | null,
    query:
        string,
): UseQueryResult<
    readonly MembershipCandidateResource[],
    BrowserApiFailure
> {
    const apiClient =
        useApiClient();

    const membershipId =
        useMembershipId();

    const trimmedQuery =
        query.trim();

    return useQuery<
        readonly MembershipCandidateResource[],
        BrowserApiFailure
    >({
        queryKey: [
            'settings',
            'organizations',
            organizationId
            ?? '',
            'assignments',
            'candidate-memberships',
            trimmedQuery,
        ],

        enabled:
            membershipId !== null
            && organizationId !== null
            && trimmedQuery.length >= 2,

        queryFn: async () => {
            if (
                membershipId === null
                || organizationId === null
            ) {
                throw new Error(
                    'useMembershipCandidatesQuery executed without a ready Membership context or organizationId.',
                );
            }

            const result =
                await executeBrowserApiReadRequest(
                    () =>
                        apiClient.GET(
                            '/api/v1/core/organizations/{organization}/assignments/candidate-memberships',
                            {
                                params: {
                                    path: {
                                        organization:
                                            organizationId,
                                    },

                                    query: {
                                        q:
                                            trimmedQuery,
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

            return (
                result.data?.data
                ?? []
            );
        },
    });
}
