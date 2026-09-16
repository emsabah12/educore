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

export type CompensationAssignmentResource =
    ApiComponents['schemas']['CompensationAssignmentResource'];

/*
 * HR-006 §7.3 — SENGAJA tenant-wide (bukan Organizational Workspace
 * scope) untuk rilis pertama ini, sama seperti katalog Compensation
 * Component/Benefit Program — lihat docblock
 * CompensationAssignmentController di backend.
 */
function useMembershipId(): string | null {
    const workspaceState =
        useWorkspaceContextState();

    return workspaceState.status === 'ready'
        ? workspaceState.context.membership.id
        : null;
}

export function compensationAssignmentsQueryKey(
    employmentId: string,
): readonly unknown[] {
    return [
        'hr',
        'compensation-assignments',
        employmentId,
    ];
}

export function useCompensationAssignmentsQuery(
    employmentId: string,
): UseQueryResult<
    readonly CompensationAssignmentResource[],
    BrowserApiFailure
> {
    const apiClient =
        useApiClient();

    const membershipId =
        useMembershipId();

    return useQuery<
        readonly CompensationAssignmentResource[],
        BrowserApiFailure
    >({
        queryKey:
            compensationAssignmentsQueryKey(
                employmentId,
            ),

        enabled:
            membershipId !== null
            && employmentId !== '',

        queryFn: async () => {
            if (membershipId === null) {
                throw new Error(
                    'useCompensationAssignmentsQuery executed without a ready Membership context.',
                );
            }

            const result =
                await executeBrowserApiReadRequest(
                    () =>
                        apiClient.GET(
                            '/api/v1/hr/employments/{employmentId}/compensation-assignments',
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
