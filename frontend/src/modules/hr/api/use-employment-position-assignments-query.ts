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

export type EmploymentPositionAssignmentResource =
    ApiComponents['schemas']['EmploymentPositionAssignmentResource'];

function useMembershipId(): string | null {
    const workspaceState =
        useWorkspaceContextState();

    return workspaceState.status === 'ready'
        ? workspaceState.context.membership.id
        : null;
}

export function employmentPositionAssignmentsQueryKey(
    employmentId: string,
): readonly unknown[] {
    return [
        'hr',
        'employment-position-assignments',
        employmentId,
    ];
}

export function useEmploymentPositionAssignmentsQuery(
    employmentId: string,
    shouldFetch: boolean,
): UseQueryResult<
    readonly EmploymentPositionAssignmentResource[],
    BrowserApiFailure
> {
    const apiClient =
        useApiClient();

    const membershipId =
        useMembershipId();

    return useQuery<
        readonly EmploymentPositionAssignmentResource[],
        BrowserApiFailure
    >({
        queryKey:
            employmentPositionAssignmentsQueryKey(
                employmentId,
            ),

        enabled:
            shouldFetch
            && membershipId !== null
            && employmentId !== '',

        queryFn: async () => {
            if (membershipId === null) {
                throw new Error(
                    'useEmploymentPositionAssignmentsQuery executed without a ready Membership context.',
                );
            }

            const result =
                await executeBrowserApiReadRequest(
                    () =>
                        apiClient.GET(
                            '/api/v1/hr/employments/{employmentId}/position-assignments',
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
