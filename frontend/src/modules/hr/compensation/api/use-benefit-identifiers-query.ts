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

export type BenefitIdentifierEntry =
    ApiComponents['schemas']['BenefitIdentifierEntry'];

function useMembershipId(): string | null {
    const workspaceState =
        useWorkspaceContextState();

    return workspaceState.status === 'ready'
        ? workspaceState.context.membership.id
        : null;
}

export function benefitIdentifiersQueryKey(
    participationId: string,
): readonly unknown[] {
    return [
        'hr',
        'benefit-identifiers',
        participationId,
    ];
}

/*
 * §HR-006 §7.7 — mengembalikan value TERDEKRIPSI, operasi lebih
 * sensitif daripada sekadar menulis. `enabled` SENGAJA butuh
 * persetujuan eksplisit (`shouldFetch`) dari pemanggil — komponen
 * TIDAK boleh auto-fetch identifier untuk semua baris partisipasi
 * sekaligus saat halaman dimuat, hanya saat satu baris tertentu
 * benar-benar dibuka oleh pengguna.
 */
export function useBenefitIdentifiersQuery(
    participationId: string,
    shouldFetch: boolean,
): UseQueryResult<
    readonly BenefitIdentifierEntry[],
    BrowserApiFailure
> {
    const apiClient =
        useApiClient();

    const membershipId =
        useMembershipId();

    return useQuery<
        readonly BenefitIdentifierEntry[],
        BrowserApiFailure
    >({
        queryKey:
            benefitIdentifiersQueryKey(
                participationId,
            ),

        enabled:
            shouldFetch
            && membershipId !== null
            && participationId !== '',

        queryFn: async () => {
            if (membershipId === null) {
                throw new Error(
                    'useBenefitIdentifiersQuery executed without a ready Membership context.',
                );
            }

            const result =
                await executeBrowserApiReadRequest(
                    () =>
                        apiClient.GET(
                            '/api/v1/hr/benefit-participations/{participationId}/identifiers',
                            {
                                params: {
                                    path: {
                                        participationId,
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
