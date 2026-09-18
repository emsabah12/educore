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
    recruitmentVacanciesQueryKey,
    type RecruitmentVacancyResource,
} from '@/modules/hr/api/use-recruitment-vacancies-query';
import {
    executeBrowserApiRequest,
    type BrowserApiFailure,
} from '@/platform/api';
import {
    createBrowserMembershipHeaderParams,
} from '@/platform/api/request-context';

export interface CreateRecruitmentVacancyInput {
    readonly code: string;
    readonly title: string;
    readonly positionId: string;
    readonly organizationId: string;
    readonly organizationUnitId: string | null;
    readonly requestedHeadcount: number;
    readonly description: string | null;
}

function useMembershipId(): string | null {
    const workspaceState =
        useWorkspaceContextState();

    return workspaceState.status === 'ready'
        ? workspaceState.context.membership.id
        : null;
}

function useInvalidateVacancies() {
    const queryClient =
        useQueryClient();

    return () => {
        void queryClient.invalidateQueries(
            {
                queryKey:
                    recruitmentVacanciesQueryKey,
            },
        );
    };
}

export function useCreateRecruitmentVacancyMutation(): UseMutationResult<
    RecruitmentVacancyResource,
    BrowserApiFailure,
    CreateRecruitmentVacancyInput
> {
    const apiClient =
        useApiClient();

    const membershipId =
        useMembershipId();

    const invalidate =
        useInvalidateVacancies();

    return useMutation<
        RecruitmentVacancyResource,
        BrowserApiFailure,
        CreateRecruitmentVacancyInput
    >({
        mutationFn: async (
            input,
        ) => {
            if (membershipId === null) {
                throw new Error(
                    'useCreateRecruitmentVacancyMutation executed without a ready Membership context.',
                );
            }

            const result =
                await executeBrowserApiRequest(
                    apiClient.POST(
                        '/api/v1/hr/recruitment/vacancies',
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
                                code:
                                    input.code,

                                title:
                                    input.title,

                                position_id:
                                    input.positionId,

                                organization_id:
                                    input.organizationId,

                                organization_unit_id:
                                    input.organizationUnitId,

                                requested_headcount:
                                    input.requestedHeadcount,

                                description:
                                    input.description,
                            },
                        },
                    ),
                );

            if (! result.ok) {
                throw result;
            }

            if (result.data === undefined) {
                throw new Error(
                    'RecruitmentVacancy creation response was empty.',
                );
            }

            return result.data.data;
        },

        onSuccess: () => {
            invalidate();
        },
    });
}

function useVacancyPlainTransitionMutation(
    path:
        | '/api/v1/hr/recruitment/vacancies/{vacancyId}/submit'
        | '/api/v1/hr/recruitment/vacancies/{vacancyId}/open'
        | '/api/v1/hr/recruitment/vacancies/{vacancyId}/close'
        | '/api/v1/hr/recruitment/vacancies/{vacancyId}/cancel',
    errorContext: string,
): UseMutationResult<
    RecruitmentVacancyResource,
    BrowserApiFailure,
    { vacancyId: string }
> {
    const apiClient =
        useApiClient();

    const membershipId =
        useMembershipId();

    const invalidate =
        useInvalidateVacancies();

    return useMutation<
        RecruitmentVacancyResource,
        BrowserApiFailure,
        { vacancyId: string }
    >({
        mutationFn: async (
            input,
        ) => {
            if (membershipId === null) {
                throw new Error(
                    `${errorContext} executed without a ready Membership context.`,
                );
            }

            const result =
                await executeBrowserApiRequest(
                    apiClient.POST(
                        path,
                        {
                            params: {
                                path: {
                                    vacancyId:
                                        input.vacancyId,
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

            if (result.data === undefined) {
                throw new Error(
                    `${errorContext} response was empty.`,
                );
            }

            return result.data.data;
        },

        onSuccess: () => {
            invalidate();
        },
    });
}

function useVacancyDecisionMutation(
    path:
        | '/api/v1/hr/recruitment/vacancies/{vacancyId}/approve'
        | '/api/v1/hr/recruitment/vacancies/{vacancyId}/reject',
    errorContext: string,
): UseMutationResult<
    RecruitmentVacancyResource,
    BrowserApiFailure,
    { vacancyId: string; reason: string | null }
> {
    const apiClient =
        useApiClient();

    const membershipId =
        useMembershipId();

    const invalidate =
        useInvalidateVacancies();

    return useMutation<
        RecruitmentVacancyResource,
        BrowserApiFailure,
        { vacancyId: string; reason: string | null }
    >({
        mutationFn: async (
            input,
        ) => {
            if (membershipId === null) {
                throw new Error(
                    `${errorContext} executed without a ready Membership context.`,
                );
            }

            const result =
                await executeBrowserApiRequest(
                    apiClient.POST(
                        path,
                        {
                            params: {
                                path: {
                                    vacancyId:
                                        input.vacancyId,
                                },

                                header:
                                    createBrowserMembershipHeaderParams(
                                        {
                                            membershipId,
                                        },
                                    ),
                            },

                            body: {
                                reason:
                                    input.reason,
                            },
                        },
                    ),
                );

            if (! result.ok) {
                throw result;
            }

            if (result.data === undefined) {
                throw new Error(
                    `${errorContext} response was empty.`,
                );
            }

            return result.data.data;
        },

        onSuccess: () => {
            invalidate();
        },
    });
}

export function useSubmitRecruitmentVacancyMutation(): UseMutationResult<
    RecruitmentVacancyResource,
    BrowserApiFailure,
    { vacancyId: string }
> {
    return useVacancyPlainTransitionMutation(
        '/api/v1/hr/recruitment/vacancies/{vacancyId}/submit',
        'useSubmitRecruitmentVacancyMutation',
    );
}

export function useApproveRecruitmentVacancyMutation(): UseMutationResult<
    RecruitmentVacancyResource,
    BrowserApiFailure,
    { vacancyId: string; reason: string | null }
> {
    return useVacancyDecisionMutation(
        '/api/v1/hr/recruitment/vacancies/{vacancyId}/approve',
        'useApproveRecruitmentVacancyMutation',
    );
}

export function useRejectRecruitmentVacancyMutation(): UseMutationResult<
    RecruitmentVacancyResource,
    BrowserApiFailure,
    { vacancyId: string; reason: string | null }
> {
    return useVacancyDecisionMutation(
        '/api/v1/hr/recruitment/vacancies/{vacancyId}/reject',
        'useRejectRecruitmentVacancyMutation',
    );
}

export function useOpenRecruitmentVacancyMutation(): UseMutationResult<
    RecruitmentVacancyResource,
    BrowserApiFailure,
    { vacancyId: string }
> {
    return useVacancyPlainTransitionMutation(
        '/api/v1/hr/recruitment/vacancies/{vacancyId}/open',
        'useOpenRecruitmentVacancyMutation',
    );
}

export function useCloseRecruitmentVacancyMutation(): UseMutationResult<
    RecruitmentVacancyResource,
    BrowserApiFailure,
    { vacancyId: string }
> {
    return useVacancyPlainTransitionMutation(
        '/api/v1/hr/recruitment/vacancies/{vacancyId}/close',
        'useCloseRecruitmentVacancyMutation',
    );
}

export function useCancelRecruitmentVacancyMutation(): UseMutationResult<
    RecruitmentVacancyResource,
    BrowserApiFailure,
    { vacancyId: string }
> {
    return useVacancyPlainTransitionMutation(
        '/api/v1/hr/recruitment/vacancies/{vacancyId}/cancel',
        'useCancelRecruitmentVacancyMutation',
    );
}
