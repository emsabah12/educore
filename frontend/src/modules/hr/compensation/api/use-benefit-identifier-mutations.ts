import {
    useMutation,
    useQueryClient,
    type UseMutationResult,
} from '@tanstack/react-query';

import {
    useApiClient,
} from '@/app/api/ApiClientProvider';
import {
    useWorkspaceContextState,
} from '@/app/workspace/WorkspaceContextProvider';
import {
    benefitIdentifiersQueryKey,
} from '@/modules/hr/compensation/api/use-benefit-identifiers-query';
import type {
    ApiComponents,
} from '@/platform/api';
import {
    executeBrowserApiRequest,
    type BrowserApiFailure,
} from '@/platform/api';
import {
    createBrowserMembershipHeaderParams,
} from '@/platform/api/request-context';

export type BenefitIdentifierCreatedEntry =
    ApiComponents['schemas']['BenefitIdentifierCreatedEntry'];

export interface CreateBenefitIdentifierInput {
    readonly participationId: string;
    readonly identifier_type: string;
    readonly value: string;
    readonly issuer: string | null;
}

function useMembershipId(): string | null {
    const workspaceState =
        useWorkspaceContextState();

    return workspaceState.status === 'ready'
        ? workspaceState.context.membership.id
        : null;
}

/*
 * §HR-006 §7.7 — `value` mentah TIDAK PERNAH dikirim balik server
 * (lihat StoreBenefitIdentifierRequest), dan hook ini juga TIDAK
 * PERNAH menyimpan atau meneruskan input.value ke React Query cache
 * — hanya metadata (id, identifier_type, status) yang jadi hasil
 * mutation ini.
 */
export function useCreateBenefitIdentifierMutation(): UseMutationResult<
    BenefitIdentifierCreatedEntry,
    BrowserApiFailure,
    CreateBenefitIdentifierInput
> {
    const apiClient =
        useApiClient();

    const membershipId =
        useMembershipId();

    const queryClient =
        useQueryClient();

    return useMutation<
        BenefitIdentifierCreatedEntry,
        BrowserApiFailure,
        CreateBenefitIdentifierInput
    >({
        mutationFn: async (
            input,
        ) => {
            if (membershipId === null) {
                throw new Error(
                    'useCreateBenefitIdentifierMutation executed without a ready Membership context.',
                );
            }

            const result =
                await executeBrowserApiRequest(
                    apiClient.POST(
                        '/api/v1/hr/benefit-participations/{participationId}/identifiers',
                        {
                            params: {
                                path: {
                                    participationId:
                                        input.participationId,
                                },

                                header:
                                    createBrowserMembershipHeaderParams(
                                        {
                                            membershipId,
                                        },
                                    ),
                            },

                            body: {
                                identifier_type:
                                    input.identifier_type,

                                value:
                                    input.value,

                                issuer:
                                    input.issuer,

                                issued_at:
                                    null,

                                expires_at:
                                    null,
                            },
                        },
                    ),
                );

            if (! result.ok) {
                throw result;
            }

            if (result.data === undefined) {
                throw new Error(
                    'Benefit Identifier creation response was empty.',
                );
            }

            return result.data.data;
        },

        onSuccess: (
            _data,
            variables,
        ) => {
            void queryClient.invalidateQueries(
                {
                    queryKey:
                        benefitIdentifiersQueryKey(
                            variables.participationId,
                        ),
                },
            );
        },
    });
}
