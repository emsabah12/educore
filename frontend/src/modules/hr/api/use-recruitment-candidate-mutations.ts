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
    recruitmentCandidatesQueryKey,
    type RecruitmentCandidateResource,
} from '@/modules/hr/api/use-recruitment-candidates-query';
import {
    executeBrowserApiRequest,
    type BrowserApiFailure,
} from '@/platform/api';
import {
    createBrowserMembershipHeaderParams,
} from '@/platform/api/request-context';

export interface RecruitmentCandidateIdentifierInput {
    readonly type: string;
    readonly issuingCountryCode: string;
    readonly value: string;
}

export interface CreateRecruitmentCandidateInput {
    readonly displayName: string;
    readonly birthDate: string | null;
    readonly primaryEmail: string | null;
    readonly primaryPhone: string | null;
    readonly source: string | null;
    readonly identifiers: readonly RecruitmentCandidateIdentifierInput[] | null;
}

function useMembershipId(): string | null {
    const workspaceState =
        useWorkspaceContextState();

    return workspaceState.status === 'ready'
        ? workspaceState.context.membership.id
        : null;
}

export function useCreateRecruitmentCandidateMutation(): UseMutationResult<
    RecruitmentCandidateResource,
    BrowserApiFailure,
    CreateRecruitmentCandidateInput
> {
    const apiClient =
        useApiClient();

    const membershipId =
        useMembershipId();

    const queryClient =
        useQueryClient();

    return useMutation<
        RecruitmentCandidateResource,
        BrowserApiFailure,
        CreateRecruitmentCandidateInput
    >({
        mutationFn: async (
            input,
        ) => {
            if (membershipId === null) {
                throw new Error(
                    'useCreateRecruitmentCandidateMutation executed without a ready Membership context.',
                );
            }

            const result =
                await executeBrowserApiRequest(
                    apiClient.POST(
                        '/api/v1/hr/recruitment/candidates',
                        {
                            params: {
                                header:
                                    createBrowserMembershipHeaderParams(
                                        {
                                            membershipId,
                                        },
                                    ),
                            },

                            body: {
                                display_name:
                                    input.displayName,

                                birth_date:
                                    input.birthDate,

                                primary_email:
                                    input.primaryEmail,

                                primary_phone:
                                    input.primaryPhone,

                                source:
                                    input.source,

                                identifiers:
                                    input.identifiers === null
                                        ? null
                                        : input.identifiers.map(
                                            (
                                                identifier,
                                            ) => ({
                                                type:
                                                    identifier.type,

                                                issuing_country_code:
                                                    identifier.issuingCountryCode,

                                                value:
                                                    identifier.value,
                                            }),
                                        ),
                            },
                        },
                    ),
                );

            if (! result.ok) {
                throw result;
            }

            if (result.data === undefined) {
                throw new Error(
                    'RecruitmentCandidate creation response was empty.',
                );
            }

            return result.data.data;
        },

        onSuccess: () => {
            void queryClient.invalidateQueries(
                {
                    queryKey:
                        recruitmentCandidatesQueryKey,
                },
            );
        },
    });
}

export interface StoreRecruitmentCandidateIdentifierInput {
    readonly candidateId: string;
    readonly type: string;
    readonly issuingCountryCode: string;
    readonly value: string;
}

export interface RecruitmentCandidateIdentifierCreatedResource {
    readonly id: string;
    readonly candidate_id: string;
    readonly type: string;
    readonly issuing_country_code: string;
    readonly status: string;
}

/**
 * §Melengkapi identifier kuat (mis. NIK) ke Candidate yang SUDAH ADA
 * -- memakai endpoint baru yang menutup gap: kandidat yang dibuat
 * lewat form ini SEBELUM field identitas ditambahkan (atau memang
 * sengaja dibuat dulu, dilengkapi belakangan setelah verifikasi
 * dokumen) selalu gagal di "Proses Perekrutan" sampai identitas ini
 * dilengkapi -- lihat HireConversionService::resolveCandidateIdentity().
 */
export function useStoreRecruitmentCandidateIdentifierMutation(): UseMutationResult<
    RecruitmentCandidateIdentifierCreatedResource,
    BrowserApiFailure,
    StoreRecruitmentCandidateIdentifierInput
> {
    const apiClient =
        useApiClient();

    const membershipId =
        useMembershipId();

    return useMutation<
        RecruitmentCandidateIdentifierCreatedResource,
        BrowserApiFailure,
        StoreRecruitmentCandidateIdentifierInput
    >({
        mutationFn: async (
            input,
        ) => {
            if (membershipId === null) {
                throw new Error(
                    'useStoreRecruitmentCandidateIdentifierMutation executed without a ready Membership context.',
                );
            }

            const result =
                await executeBrowserApiRequest(
                    apiClient.POST(
                        '/api/v1/hr/recruitment/candidates/{candidateId}/identifiers',
                        {
                            params: {
                                path: {
                                    candidateId:
                                        input.candidateId,
                                },

                                header:
                                    createBrowserMembershipHeaderParams(
                                        {
                                            membershipId,
                                        },
                                    ),
                            },

                            body: {
                                type:
                                    input.type,

                                issuing_country_code:
                                    input.issuingCountryCode,

                                value:
                                    input.value,
                            },
                        },
                    ),
                );

            if (! result.ok) {
                throw result;
            }

            if (result.data === undefined) {
                throw new Error(
                    'RecruitmentCandidateIdentifier creation response was empty.',
                );
            }

            return result.data.data;
        },
    });
}
