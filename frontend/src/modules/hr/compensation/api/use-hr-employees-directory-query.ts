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

export type HrEmployeeDirectoryEntry =
    ApiComponents['schemas']['EmployeeResource'];

export interface HrEmployeeDirectoryPage {
    readonly items: readonly HrEmployeeDirectoryEntry[];
    readonly currentPage: number;
    readonly lastPage: number;
}

/*
 * Kelola Kompensasi & Benefit adalah TENANT-level concern (lihat
 * CompensationAdjustmentController dkk. di backend, didaftarkan di
 * bawah Route::middleware([InjectTenantContext::class]), BUKAN di
 * grup 'v1/hr/workspace' seperti hr.workforce). Sama seperti
 * settings/organizations, cukup Membership context yang siap —
 * TIDAK butuh Organizational Workspace terpilih.
 */
function useMembershipId(): string | null {
    const workspaceState =
        useWorkspaceContextState();

    return workspaceState.status === 'ready'
        ? workspaceState.context.membership.id
        : null;
}

export function hrEmployeesDirectoryQueryKey(
    page: number,
): readonly unknown[] {
    return [
        'hr',
        'compensation',
        'employees-directory',
        page,
    ];
}

export function useHrEmployeesDirectoryQuery(
    page: number,
): UseQueryResult<
    HrEmployeeDirectoryPage,
    BrowserApiFailure
> {
    const apiClient =
        useApiClient();

    const membershipId =
        useMembershipId();

    return useQuery<
        HrEmployeeDirectoryPage,
        BrowserApiFailure
    >({
        queryKey:
            hrEmployeesDirectoryQueryKey(
                page,
            ),

        enabled:
            membershipId !== null,

        queryFn: async () => {
            if (membershipId === null) {
                throw new Error(
                    'useHrEmployeesDirectoryQuery executed without a ready Membership context.',
                );
            }

            const result =
                await executeBrowserApiReadRequest(
                    () =>
                        apiClient.GET(
                            '/api/v1/hr/employees',
                            {
                                params: {
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
