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

export type EmployeeBenefitParticipationResource =
    ApiComponents['schemas']['EmployeeBenefitParticipationResource'];

/*
 * HR-006 §7.6 — SENGAJA tenant-wide untuk rilis pertama ini, pola
 * sama persis dengan Compensation Assignment — lihat docblock
 * EmployeeBenefitParticipationController di backend.
 */
function useMembershipId(): string | null {
    const workspaceState =
        useWorkspaceContextState();

    return workspaceState.status === 'ready'
        ? workspaceState.context.membership.id
        : null;
}

export function benefitParticipationsQueryKey(
    employmentId: string,
): readonly unknown[] {
    return [
        'hr',
        'benefit-participations',
        employmentId,
    ];
}

export function useBenefitParticipationsQuery(
    employmentId: string,
): UseQueryResult<
    readonly EmployeeBenefitParticipationResource[],
    BrowserApiFailure
> {
    const apiClient =
        useApiClient();

    const membershipId =
        useMembershipId();

    return useQuery<
        readonly EmployeeBenefitParticipationResource[],
        BrowserApiFailure
    >({
        queryKey:
            benefitParticipationsQueryKey(
                employmentId,
            ),

        enabled:
            membershipId !== null
            && employmentId !== '',

        queryFn: async () => {
            if (membershipId === null) {
                throw new Error(
                    'useBenefitParticipationsQuery executed without a ready Membership context.',
                );
            }

            const result =
                await executeBrowserApiReadRequest(
                    () =>
                        apiClient.GET(
                            '/api/v1/hr/employments/{employmentId}/benefit-participations',
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
