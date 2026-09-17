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
    benefitParticipationsQueryKey,
    type EmployeeBenefitParticipationResource,
} from '@/modules/hr/compensation/api/use-benefit-participations-query';
import {
    executeBrowserApiRequest,
    type BrowserApiFailure,
} from '@/platform/api';
import {
    createBrowserMembershipHeaderParams,
} from '@/platform/api/request-context';

export interface BenefitParticipationDraftInput {
    readonly benefit_program_id: string;
    readonly effective_from: string;
    readonly effective_to: string | null;
    readonly notes: string | null;
}

function useMembershipId(): string | null {
    const workspaceState =
        useWorkspaceContextState();

    return workspaceState.status === 'ready'
        ? workspaceState.context.membership.id
        : null;
}

function useInvalidateParticipations() {
    const queryClient =
        useQueryClient();

    return (
        employmentId: string,
    ) => {
        void queryClient.invalidateQueries(
            {
                queryKey:
                    benefitParticipationsQueryKey(
                        employmentId,
                    ),
            },
        );
    };
}

export function useCreateBenefitParticipationMutation(
    employmentId: string,
): UseMutationResult<
    EmployeeBenefitParticipationResource,
    BrowserApiFailure,
    BenefitParticipationDraftInput
> {
    const apiClient =
        useApiClient();

    const membershipId =
        useMembershipId();

    const invalidate =
        useInvalidateParticipations();

    return useMutation<
        EmployeeBenefitParticipationResource,
        BrowserApiFailure,
        BenefitParticipationDraftInput
    >({
        mutationFn: async (
            input,
        ) => {
            if (membershipId === null) {
                throw new Error(
                    'useCreateBenefitParticipationMutation executed without a ready Membership context.',
                );
            }

            const result =
                await executeBrowserApiRequest(
                    apiClient.POST(
                        '/api/v1/hr/employments/{employmentId}/benefit-participations',
                        {
                            params: {
                                path: {
                                    employmentId,
                                },

                                header:
                                    createBrowserMembershipHeaderParams(
                                        {
                                            membershipId,
                                        },
                                    ),
                            },

                            body: {
                                ...input,

                                beneficiary_person_id:
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
                    'Benefit Participation creation response was empty.',
                );
            }

            return result.data.data;
        },

        onSuccess: () => {
            invalidate(
                employmentId,
            );
        },
    });
}

type ParticipationTransitionMutation = UseMutationResult<
    EmployeeBenefitParticipationResource,
    BrowserApiFailure,
    string
>;

function useParticipationTransitionMutation(
    employmentId: string,
    path:
        | '/api/v1/hr/employments/{employmentId}/benefit-participations/{participationId}/enroll'
        | '/api/v1/hr/employments/{employmentId}/benefit-participations/{participationId}/suspend'
        | '/api/v1/hr/employments/{employmentId}/benefit-participations/{participationId}/reinstate',
    errorMessage: string,
): ParticipationTransitionMutation {
    const apiClient =
        useApiClient();

    const membershipId =
        useMembershipId();

    const invalidate =
        useInvalidateParticipations();

    return useMutation<
        EmployeeBenefitParticipationResource,
        BrowserApiFailure,
        string
    >({
        mutationFn: async (
            participationId,
        ) => {
            if (membershipId === null) {
                throw new Error(
                    errorMessage,
                );
            }

            const result =
                await executeBrowserApiRequest(
                    apiClient.POST(
                        path,
                        {
                            params: {
                                path: {
                                    employmentId,
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

            if (result.data === undefined) {
                throw new Error(
                    'Benefit Participation transition response was empty.',
                );
            }

            return result.data.data;
        },

        onSuccess: () => {
            invalidate(
                employmentId,
            );
        },
    });
}

export function useEnrollBenefitParticipationMutation(
    employmentId: string,
): ParticipationTransitionMutation {
    return useParticipationTransitionMutation(
        employmentId,
        '/api/v1/hr/employments/{employmentId}/benefit-participations/{participationId}/enroll',
        'useEnrollBenefitParticipationMutation executed without a ready Membership context.',
    );
}

export function useSuspendBenefitParticipationMutation(
    employmentId: string,
): ParticipationTransitionMutation {
    return useParticipationTransitionMutation(
        employmentId,
        '/api/v1/hr/employments/{employmentId}/benefit-participations/{participationId}/suspend',
        'useSuspendBenefitParticipationMutation executed without a ready Membership context.',
    );
}

export function useReinstateBenefitParticipationMutation(
    employmentId: string,
): ParticipationTransitionMutation {
    return useParticipationTransitionMutation(
        employmentId,
        '/api/v1/hr/employments/{employmentId}/benefit-participations/{participationId}/reinstate',
        'useReinstateBenefitParticipationMutation executed without a ready Membership context.',
    );
}

export interface EndBenefitParticipationInput {
    readonly participationId: string;
    readonly endDate: string;
}

export function useEndBenefitParticipationMutation(
    employmentId: string,
): UseMutationResult<
    EmployeeBenefitParticipationResource,
    BrowserApiFailure,
    EndBenefitParticipationInput
> {
    const apiClient =
        useApiClient();

    const membershipId =
        useMembershipId();

    const invalidate =
        useInvalidateParticipations();

    return useMutation<
        EmployeeBenefitParticipationResource,
        BrowserApiFailure,
        EndBenefitParticipationInput
    >({
        mutationFn: async (
            input,
        ) => {
            if (membershipId === null) {
                throw new Error(
                    'useEndBenefitParticipationMutation executed without a ready Membership context.',
                );
            }

            const result =
                await executeBrowserApiRequest(
                    apiClient.POST(
                        '/api/v1/hr/employments/{employmentId}/benefit-participations/{participationId}/end',
                        {
                            params: {
                                path: {
                                    employmentId,

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
                                end_date:
                                    input.endDate,
                            },
                        },
                    ),
                );

            if (! result.ok) {
                throw result;
            }

            if (result.data === undefined) {
                throw new Error(
                    'Benefit Participation end response was empty.',
                );
            }

            return result.data.data;
        },

        onSuccess: () => {
            invalidate(
                employmentId,
            );
        },
    });
}
