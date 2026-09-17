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

export type CompensationAdjustmentResource =
    ApiComponents['schemas']['CompensationAdjustmentResource'];

/*
 * HR-006 §7.8 — SENGAJA tenant-wide untuk rilis pertama ini, pola
 * sama persis dengan Compensation Assignment/Benefit Participation
 * — lihat docblock CompensationAdjustmentController di backend.
 */
function useMembershipId(): string | null {
    const workspaceState =
        useWorkspaceContextState();

    return workspaceState.status === 'ready'
        ? workspaceState.context.membership.id
        : null;
}

export function compensationAdjustmentsQueryKey(
    employmentId: string,
): readonly unknown[] {
    return [
        'hr',
        'compensation-adjustments',
        employmentId,
    ];
}

export function useCompensationAdjustmentsQuery(
    employmentId: string,
): UseQueryResult<
    readonly CompensationAdjustmentResource[],
    BrowserApiFailure
> {
    const apiClient =
        useApiClient();

    const membershipId =
        useMembershipId();

    return useQuery<
        readonly CompensationAdjustmentResource[],
        BrowserApiFailure
    >({
        queryKey:
            compensationAdjustmentsQueryKey(
                employmentId,
            ),

        enabled:
            membershipId !== null
            && employmentId !== '',

        queryFn: async () => {
            if (membershipId === null) {
                throw new Error(
                    'useCompensationAdjustmentsQuery executed without a ready Membership context.',
                );
            }

            const result =
                await executeBrowserApiReadRequest(
                    () =>
                        apiClient.GET(
                            '/api/v1/hr/employments/{employmentId}/compensation-adjustments',
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
