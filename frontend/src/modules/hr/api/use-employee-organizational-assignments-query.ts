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
    createBrowserWorkspaceHeaderParams,
} from '@/platform/api/request-context';

export type EmployeeOrganizationalAssignmentEntry =
    ApiComponents['schemas']['EmployeeOrganizationalAssignmentEntry'];

function useWorkspaceHeaderInputs(): {
    membershipId: string | null;
    organizationalAssignmentId: string | null;
} {
    const workspaceState =
        useWorkspaceContextState();

    const isReady =
        workspaceState.status === 'ready';

    return {
        membershipId:
            isReady
                ? workspaceState.context.membership.id
                : null,

        organizationalAssignmentId:
            isReady
            && workspaceState.current.organizational_assignment_id !== null
                ? workspaceState.current.organizational_assignment_id
                : null,
    };
}

export function useEmployeeOrganizationalAssignmentsQuery(
    employeeId: string,
    shouldFetch: boolean,
): UseQueryResult<
    readonly EmployeeOrganizationalAssignmentEntry[],
    BrowserApiFailure
> {
    const apiClient =
        useApiClient();

    const {
        membershipId,
        organizationalAssignmentId,
    } =
        useWorkspaceHeaderInputs();

    return useQuery<
        readonly EmployeeOrganizationalAssignmentEntry[],
        BrowserApiFailure
    >({
        queryKey: [
            'hr',
            'employee-organizational-assignments',
            employeeId,
        ],

        enabled:
            shouldFetch
            && membershipId !== null
            && organizationalAssignmentId !== null
            && employeeId !== '',

        queryFn: async () => {
            if (
                membershipId === null
                || organizationalAssignmentId === null
            ) {
                throw new Error(
                    'useEmployeeOrganizationalAssignmentsQuery executed without a ready organizational workspace.',
                );
            }

            const result =
                await executeBrowserApiReadRequest(
                    () =>
                        apiClient.GET(
                            '/api/v1/hr/workspace/employees/{employeeId}/organizational-assignments',
                            {
                                params: {
                                    path: {
                                        employeeId,
                                    },

                                    header:
                                        createBrowserWorkspaceHeaderParams(
                                            {
                                                membershipId,
                                                organizationalAssignmentId,
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
