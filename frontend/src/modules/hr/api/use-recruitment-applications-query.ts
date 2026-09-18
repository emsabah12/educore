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

export type RecruitmentApplicationResource =
    ApiComponents['schemas']['RecruitmentApplicationResource'];

function useMembershipId(): string | null {
    const workspaceState =
        useWorkspaceContextState();

    return workspaceState.status === 'ready'
        ? workspaceState.context.membership.id
        : null;
}

export function recruitmentApplicationsQueryKey(
    vacancyId: string,
): readonly unknown[] {
    return [
        'hr',
        'recruitment-applications',
        vacancyId,
    ];
}

export function useRecruitmentApplicationsQuery(
    vacancyId: string,
): UseQueryResult<
    readonly RecruitmentApplicationResource[],
    BrowserApiFailure
> {
    const apiClient =
        useApiClient();

    const membershipId =
        useMembershipId();

    return useQuery<
        readonly RecruitmentApplicationResource[],
        BrowserApiFailure
    >({
        queryKey:
            recruitmentApplicationsQueryKey(
                vacancyId,
            ),

        enabled:
            membershipId !== null
            && vacancyId !== '',

        queryFn: async () => {
            if (membershipId === null) {
                throw new Error(
                    'useRecruitmentApplicationsQuery executed without a ready Membership context.',
                );
            }

            const result =
                await executeBrowserApiReadRequest(
                    () =>
                        apiClient.GET(
                            '/api/v1/hr/recruitment/vacancies/{vacancyId}/applications',
                            {
                                params: {
                                    path: {
                                        vacancyId,
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
