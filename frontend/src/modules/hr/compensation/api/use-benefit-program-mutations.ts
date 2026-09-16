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
    benefitProgramsQueryKey,
    type BenefitProgramResource,
} from '@/modules/hr/compensation/api/use-benefit-programs-query';
import {
    executeBrowserApiRequest,
    type BrowserApiFailure,
} from '@/platform/api';
import {
    createBrowserMembershipHeaderParams,
} from '@/platform/api/request-context';

export interface CreateBenefitProgramInput {
    readonly code: string;
    readonly name: string;
    readonly category: BenefitProgramResource['category'];
    readonly beneficiary_scope: BenefitProgramResource['beneficiary_scope'];
    readonly payroll_relevance: BenefitProgramResource['payroll_relevance'];
    readonly description: string | null;
}

function useMembershipId(): string | null {
    const workspaceState =
        useWorkspaceContextState();

    return workspaceState.status === 'ready'
        ? workspaceState.context.membership.id
        : null;
}

export function useCreateBenefitProgramMutation(): UseMutationResult<
    BenefitProgramResource,
    BrowserApiFailure,
    CreateBenefitProgramInput
> {
    const apiClient =
        useApiClient();

    const membershipId =
        useMembershipId();

    const queryClient =
        useQueryClient();

    return useMutation<
        BenefitProgramResource,
        BrowserApiFailure,
        CreateBenefitProgramInput
    >({
        mutationFn: async (
            input,
        ) => {
            if (membershipId === null) {
                throw new Error(
                    'useCreateBenefitProgramMutation executed without a ready Membership context.',
                );
            }

            const result =
                await executeBrowserApiRequest(
                    apiClient.POST(
                        '/api/v1/hr/benefits/programs',
                        {
                            params: {
                                header:
                                    createBrowserMembershipHeaderParams(
                                        {
                                            membershipId,
                                        },
                                    ),
                            },

                            body:
                                input,
                        },
                    ),
                );

            if (! result.ok) {
                throw result;
            }

            if (result.data === undefined) {
                throw new Error(
                    'Benefit Program creation response was empty.',
                );
            }

            return result.data.data;
        },

        onSuccess: () => {
            void queryClient.invalidateQueries(
                {
                    queryKey:
                        benefitProgramsQueryKey,
                },
            );
        },
    });
}
