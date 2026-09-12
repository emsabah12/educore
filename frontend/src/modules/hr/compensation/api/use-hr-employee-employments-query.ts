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

export type HrEmploymentDirectoryEntry =
    ApiComponents['schemas']['EmploymentResource'];

export interface HrEmployeeEmploymentsPage {
    readonly items: readonly HrEmploymentDirectoryEntry[];
    readonly currentPage: number;
    readonly lastPage: number;
}

/*
 * Sama seperti useHrEmployeesDirectoryQuery — TENANT-level concern
 * (GET /v1/hr/employees/{employeeId}/employments didaftarkan di
 * luar grup 'v1/hr/workspace'), cukup Membership context, TIDAK
 * butuh Organizational Workspace terpilih.
 */
function useMembershipId(): string | null {
    const workspaceState =
        useWorkspaceContextState();

    return workspaceState.status === 'ready'
        ? workspaceState.context.membership.id
        : null;
}

export function hrEmployeeEmploymentsQueryKey(
    employeeId: string,
    page: number,
): readonly unknown[] {
    return [
        'hr',
        'compensation',
        'employee-employments',
        employeeId,
        page,
    ];
}

export function useHrEmployeeEmploymentsQuery(
    employeeId: string,
    page: number,
): UseQueryResult<
    HrEmployeeEmploymentsPage,
    BrowserApiFailure
> {
    const apiClient =
        useApiClient();

    const membershipId =
        useMembershipId();

    return useQuery<
        HrEmployeeEmploymentsPage,
        BrowserApiFailure
    >({
        queryKey:
            hrEmployeeEmploymentsQueryKey(
                employeeId,
                page,
            ),

        enabled:
            membershipId !== null
            && employeeId !== '',

        queryFn: async () => {
            if (membershipId === null) {
                throw new Error(
                    'useHrEmployeeEmploymentsQuery executed without a ready Membership context.',
                );
            }

            const result =
                await executeBrowserApiReadRequest(
                    () =>
                        apiClient.GET(
                            '/api/v1/hr/employees/{employeeId}/employments',
                            {
                                params: {
                                    path: {
                                        employeeId,
                                    },

                                    header:
                                        createBrowserMembershipHeaderParams(
                                            {
                                                membershipId,
                                            },
                                        ),

                                    query: {
                                        per_page: 15,
                                        page,
                                    },
                                },
                            },
                        ),
                );

            if (! result.ok) {
                throw result;
            }

            return {
                items:
                    result.data?.data
                    ?? [],

                currentPage:
                    result.data?.meta.current_page
                    ?? 1,

                lastPage:
                    result.data?.meta.last_page
                    ?? 1,
            };
        },
    });
}
