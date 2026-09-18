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

export type EmploymentPlacementResource =
    ApiComponents['schemas']['EmploymentPlacementResource'];

/*
 * §Epic 1 — GET-nya SENGAJA tenant-wide (tidak ada varian
 * workspace-scoped untuk listing), jadi cukup header membership,
 * tanpa organizational_assignment_id.
 */
function useMembershipId(): string | null {
    const workspaceState =
        useWorkspaceContextState();

    return workspaceState.status === 'ready'
        ? workspaceState.context.membership.id
        : null;
}

export function employmentPlacementsQueryKey(
    employmentId: string,
): readonly unknown[] {
    return [
        'hr',
        'employment-placements',
        employmentId,
    ];
}

export function useEmploymentPlacementsQuery(
    employmentId: string,
    shouldFetch: boolean,
): UseQueryResult<
    readonly EmploymentPlacementResource[],
    BrowserApiFailure
> {
    const apiClient =
        useApiClient();

    const membershipId =
        useMembershipId();

    return useQuery<
        readonly EmploymentPlacementResource[],
        BrowserApiFailure
    >({
        queryKey:
            employmentPlacementsQueryKey(
                employmentId,
            ),

        enabled:
            shouldFetch
            && membershipId !== null
            && employmentId !== '',

        queryFn: async () => {
            if (membershipId === null) {
                throw new Error(
                    'useEmploymentPlacementsQuery executed without a ready Membership context.',
                );
            }

            const result =
                await executeBrowserApiReadRequest(
                    () =>
                        apiClient.GET(
                            '/api/v1/hr/employments/{employmentId}/placements',
                            {
                                params: {
                                    path: {
                                        employmentId,
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
