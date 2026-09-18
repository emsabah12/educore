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

export interface CreateRecruitmentCandidateInput {
    readonly displayName: string;
    readonly birthDate: string | null;
    readonly primaryEmail: string | null;
    readonly primaryPhone: string | null;
    readonly source: string | null;
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
