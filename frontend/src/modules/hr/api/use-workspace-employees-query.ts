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

export type WorkspaceEmployee =
    ApiComponents['schemas']['WorkspaceEmployeeResource'];

export interface WorkspaceEmployeesPage {
    readonly employees: readonly WorkspaceEmployee[];
    readonly currentPage: number;
    readonly lastPage: number;
    readonly total: number;
}

interface UseWorkspaceEmployeesQueryOptions {
    readonly page?: number;
    readonly perPage?: number;
}

/*
 * HR-013 §6 Target Employee Scope Rule requires an
 * organizational workspace to already be selected before
 * this query is meaningful. The owning page's route policy
 * (contextRequirement: 'organizational') guarantees the
 * workspace is never TENANT-scoped by the time this hook is
 * mounted, but the check below stays defensive rather than
 * asserting that invariant with a type cast.
 */
export function useWorkspaceEmployeesQuery({
    page = 1,
    perPage = 15,
}: UseWorkspaceEmployeesQueryOptions = {}): UseQueryResult<
    WorkspaceEmployeesPage,
    BrowserApiFailure
> {
    const apiClient =
        useApiClient();

    const workspaceState =
        useWorkspaceContextState();

    const isWorkspaceReady =
        workspaceState.status === 'ready';

    const organizationalAssignmentId =
        isWorkspaceReady
        && workspaceState.current.organizational_assignment_id !== null
            ? workspaceState.current.organizational_assignment_id
            : null;

    const membershipId =
        isWorkspaceReady
            ? workspaceState.context.membership.id
            : null;

    return useQuery<
        WorkspaceEmployeesPage,
        BrowserApiFailure
    >({
        queryKey: [
            'hr',
            'workforce',
            'employees',
            organizationalAssignmentId,
            page,
            perPage,
        ],

        enabled:
            organizationalAssignmentId !== null
            && membershipId !== null,

        queryFn: async () => {
            /*
             * `enabled` above guarantees both values are
             * non-null whenever this executes, but the
             * closure captures the outer nullable bindings,
             * so TanStack Query's own narrowing does not
             * flow through automatically.
             */
            if (
                organizationalAssignmentId === null
                || membershipId === null
            ) {
                throw new Error(
                    'useWorkspaceEmployeesQuery executed without a ready organizational workspace.',
                );
            }

            const result =
                await executeBrowserApiReadRequest(
                    () =>
                        apiClient.GET(
                            '/api/v1/hr/workspace/employees',
                            {
                                params: {
                                    header:
                                        createBrowserWorkspaceHeaderParams(
                                            {
                                                membershipId,
                                                organizationalAssignmentId,
                                            },
                                        ),

                                    query: {
                                        per_page:
                                            perPage,
                                    },
                                },
                            },
                        ),
                );

            if (! result.ok) {
                throw result;
            }

            const body =
                result.data;

            return {
                employees:
                    body?.data ?? [],

                currentPage:
                    body?.meta.current_page ?? page,

                lastPage:
                    body?.meta.last_page ?? 1,

                total:
                    body?.meta.total ?? 0,
            };
        },
    });
}