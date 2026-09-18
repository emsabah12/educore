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
    recruitmentApplicationsQueryKey,
    type RecruitmentApplicationResource,
} from '@/modules/hr/api/use-recruitment-applications-query';
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

export type RecruitmentHireConversionResource =
    ApiComponents['schemas']['RecruitmentHireConversionResource'];

export type OnboardingCaseResource =
    ApiComponents['schemas']['OnboardingCaseResource'];

function useMembershipId(): string | null {
    const workspaceState =
        useWorkspaceContextState();

    return workspaceState.status === 'ready'
        ? workspaceState.context.membership.id
        : null;
}

function useInvalidateApplications(
    vacancyId: string,
) {
    const queryClient =
        useQueryClient();

    return () => {
        void queryClient.invalidateQueries(
            {
                queryKey:
                    recruitmentApplicationsQueryKey(
                        vacancyId,
                    ),
            },
        );
    };
}

export function useCreateRecruitmentApplicationMutation(
    vacancyId: string,
): UseMutationResult<
    RecruitmentApplicationResource,
    BrowserApiFailure,
    { candidateId: string }
> {
    const apiClient =
        useApiClient();

    const membershipId =
        useMembershipId();

    const invalidate =
        useInvalidateApplications(
            vacancyId,
        );

    return useMutation<
        RecruitmentApplicationResource,
        BrowserApiFailure,
        { candidateId: string }
    >({
        mutationFn: async (
            input,
        ) => {
            if (membershipId === null) {
                throw new Error(
                    'useCreateRecruitmentApplicationMutation executed without a ready Membership context.',
                );
            }

            const result =
                await executeBrowserApiRequest(
                    apiClient.POST(
                        '/api/v1/hr/recruitment/vacancies/{vacancyId}/applications',
                        {
                            params: {
                                path: {
                                    vacancyId,
                                },

                                header:
                                    createBrowserMembershipHeaderParams(
                                        {
                                            membershipId,
                                        },
                                    ),
                            },

                            body: {
                                candidate_id:
                                    input.candidateId,
                            },
                        },
                    ),
                );

            if (! result.ok) {
                throw result;
            }

            if (result.data === undefined) {
                throw new Error(
                    'RecruitmentApplication creation response was empty.',
                );
            }

            return result.data.data;
        },

        onSuccess: () => {
            invalidate();
        },
    });
}

function useApplicationPlainTransitionMutation(
    vacancyId: string,
    path:
        | '/api/v1/hr/recruitment/applications/{applicationId}/start-processing'
        | '/api/v1/hr/recruitment/applications/{applicationId}/withdraw',
    errorContext: string,
): UseMutationResult<
    RecruitmentApplicationResource,
    BrowserApiFailure,
    { applicationId: string }
> {
    const apiClient =
        useApiClient();

    const membershipId =
        useMembershipId();

    const invalidate =
        useInvalidateApplications(
            vacancyId,
        );

    return useMutation<
        RecruitmentApplicationResource,
        BrowserApiFailure,
        { applicationId: string }
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
                                    applicationId:
                                        input.applicationId,
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

function useApplicationDecisionMutation(
    vacancyId: string,
    path:
        | '/api/v1/hr/recruitment/applications/{applicationId}/reject'
        | '/api/v1/hr/recruitment/applications/{applicationId}/approve-for-hiring',
    errorContext: string,
): UseMutationResult<
    RecruitmentApplicationResource,
    BrowserApiFailure,
    { applicationId: string; reason: string | null }
> {
    const apiClient =
        useApiClient();

    const membershipId =
        useMembershipId();

    const invalidate =
        useInvalidateApplications(
            vacancyId,
        );

    return useMutation<
        RecruitmentApplicationResource,
        BrowserApiFailure,
        { applicationId: string; reason: string | null }
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
                                    applicationId:
                                        input.applicationId,
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

export function useStartProcessingRecruitmentApplicationMutation(
    vacancyId: string,
): UseMutationResult<
    RecruitmentApplicationResource,
    BrowserApiFailure,
    { applicationId: string }
> {
    return useApplicationPlainTransitionMutation(
        vacancyId,
        '/api/v1/hr/recruitment/applications/{applicationId}/start-processing',
        'useStartProcessingRecruitmentApplicationMutation',
    );
}

export function useWithdrawRecruitmentApplicationMutation(
    vacancyId: string,
): UseMutationResult<
    RecruitmentApplicationResource,
    BrowserApiFailure,
    { applicationId: string }
> {
    return useApplicationPlainTransitionMutation(
        vacancyId,
        '/api/v1/hr/recruitment/applications/{applicationId}/withdraw',
        'useWithdrawRecruitmentApplicationMutation',
    );
}

export function useRejectRecruitmentApplicationMutation(
    vacancyId: string,
): UseMutationResult<
    RecruitmentApplicationResource,
    BrowserApiFailure,
    { applicationId: string; reason: string | null }
> {
    return useApplicationDecisionMutation(
        vacancyId,
        '/api/v1/hr/recruitment/applications/{applicationId}/reject',
        'useRejectRecruitmentApplicationMutation',
    );
}

export function useApproveForHiringRecruitmentApplicationMutation(
    vacancyId: string,
): UseMutationResult<
    RecruitmentApplicationResource,
    BrowserApiFailure,
    { applicationId: string; reason: string | null }
> {
    return useApplicationDecisionMutation(
        vacancyId,
        '/api/v1/hr/recruitment/applications/{applicationId}/approve-for-hiring',
        'useApproveForHiringRecruitmentApplicationMutation',
    );
}

export interface HireConversionInput {
    readonly applicationId: string;
    readonly employmentTypeId: string;
    readonly startDate: string;
    readonly confirmCreateNewPerson: boolean;
}

export function useHireConversionMutation(
    vacancyId: string,
): UseMutationResult<
    RecruitmentHireConversionResource,
    BrowserApiFailure,
    HireConversionInput
> {
    const apiClient =
        useApiClient();

    const membershipId =
        useMembershipId();

    const invalidate =
        useInvalidateApplications(
            vacancyId,
        );

    return useMutation<
        RecruitmentHireConversionResource,
        BrowserApiFailure,
        HireConversionInput
    >({
        mutationFn: async (
            input,
        ) => {
            if (membershipId === null) {
                throw new Error(
                    'useHireConversionMutation executed without a ready Membership context.',
                );
            }

            const result =
                await executeBrowserApiRequest(
                    apiClient.POST(
                        '/api/v1/hr/recruitment/applications/{applicationId}/hire-conversion',
                        {
                            params: {
                                path: {
                                    applicationId:
                                        input.applicationId,
                                },

                                header:
                                    createBrowserMembershipHeaderParams(
                                        {
                                            membershipId,
                                        },
                                    ),
                            },

                            body: {
                                employment_type_id:
                                    input.employmentTypeId,

                                start_date:
                                    input.startDate,

                                confirm_create_new_person:
                                    input.confirmCreateNewPerson,
                            },
                        },
                    ),
                );

            if (! result.ok) {
                throw result;
            }

            if (result.data === undefined) {
                throw new Error(
                    'Hire conversion response was empty.',
                );
            }

            return result.data.data;
        },

        onSuccess: () => {
            invalidate();
        },
    });
}

export function useCreateOnboardingCaseMutation(
    vacancyId: string,
): UseMutationResult<
    OnboardingCaseResource,
    BrowserApiFailure,
    { applicationId: string; templateId: string | null }
> {
    const apiClient =
        useApiClient();

    const membershipId =
        useMembershipId();

    const invalidate =
        useInvalidateApplications(
            vacancyId,
        );

    return useMutation<
        OnboardingCaseResource,
        BrowserApiFailure,
        { applicationId: string; templateId: string | null }
    >({
        mutationFn: async (
            input,
        ) => {
            if (membershipId === null) {
                throw new Error(
                    'useCreateOnboardingCaseMutation executed without a ready Membership context.',
                );
            }

            const result =
                await executeBrowserApiRequest(
                    apiClient.POST(
                        '/api/v1/hr/recruitment/applications/{applicationId}/onboarding',
                        {
                            params: {
                                path: {
                                    applicationId:
                                        input.applicationId,
                                },

                                header:
                                    createBrowserMembershipHeaderParams(
                                        {
                                            membershipId,
                                        },
                                    ),
                            },

                            body: {
                                template_id:
                                    input.templateId,
                            },
                        },
                    ),
                );

            if (! result.ok) {
                throw result;
            }

            if (result.data === undefined) {
                throw new Error(
                    'OnboardingCase creation response was empty.',
                );
            }

            return result.data.data;
        },

        onSuccess: () => {
            invalidate();
        },
    });
}
