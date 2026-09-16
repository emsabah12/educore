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

export type BenefitProgramResource =
    ApiComponents['schemas']['BenefitProgramResource'];

/*
 * Benefit Program is a tenant-wide catalog (HR-006 §7.5), not
 * Organizational Workspace scope — sama seperti
 * useEmploymentTypesQuery, cukup Membership context.
 */
function useMembershipId(): string | null {
    const workspaceState =
        useWorkspaceContextState();

    return workspaceState.status === 'ready'
        ? workspaceState.context.membership.id
        : null;
}

export const benefitProgramsQueryKey = [
    'hr',
    'benefit-programs',
] as const;

export function useBenefitProgramsQuery(): UseQueryResult<
    readonly BenefitProgramResource[],
    BrowserApiFailure
> {
    const apiClient =
        useApiClient();

    const membershipId =
        useMembershipId();

    return useQuery<
        readonly BenefitProgramResource[],
        BrowserApiFailure
    >({
        queryKey:
            benefitProgramsQueryKey,

        enabled:
            membershipId !== null,

        queryFn: async () => {
            if (membershipId === null) {
                throw new Error(
                    'useBenefitProgramsQuery executed without a ready Membership context.',
                );
            }

            const result =
                await executeBrowserApiReadRequest(
                    () =>
                        apiClient.GET(
                            '/api/v1/hr/benefits/programs',
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

            return result.data?.data ?? [];
        },
    });
}
