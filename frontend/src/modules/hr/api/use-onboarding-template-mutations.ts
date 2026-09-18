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
    onboardingTemplatesQueryKey,
    type OnboardingTemplateResource,
} from '@/modules/hr/api/use-onboarding-templates-query';
import {
    executeBrowserApiRequest,
    type BrowserApiFailure,
} from '@/platform/api';
import {
    createBrowserMembershipHeaderParams,
} from '@/platform/api/request-context';

export interface CreateOnboardingTemplateTaskInput {
    readonly code: string;
    readonly title: string;
    readonly category: 'DOCUMENT' | 'ORIENTATION' | 'CONTRACT' | 'ADMIN';
    readonly sequence: number;
    readonly isRequired: boolean;
    readonly requiresEvidence: boolean;
}

export interface CreateOnboardingTemplateInput {
    readonly code: string;
    readonly name: string;
    readonly tasks: readonly CreateOnboardingTemplateTaskInput[];
}

function useMembershipId(): string | null {
    const workspaceState =
        useWorkspaceContextState();

    return workspaceState.status === 'ready'
        ? workspaceState.context.membership.id
        : null;
}

export function useCreateOnboardingTemplateMutation(): UseMutationResult<
    OnboardingTemplateResource,
    BrowserApiFailure,
    CreateOnboardingTemplateInput
> {
    const apiClient =
        useApiClient();

    const membershipId =
        useMembershipId();

    const queryClient =
        useQueryClient();

    return useMutation<
        OnboardingTemplateResource,
        BrowserApiFailure,
        CreateOnboardingTemplateInput
    >({
        mutationFn: async (
            input,
        ) => {
            if (membershipId === null) {
                throw new Error(
                    'useCreateOnboardingTemplateMutation executed without a ready Membership context.',
                );
            }

            const result =
                await executeBrowserApiRequest(
                    apiClient.POST(
                        '/api/v1/hr/onboarding/templates',
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

                                name:
                                    input.name,

                                tasks:
                                    input.tasks.length === 0
                                        ? null
                                        : input.tasks.map(
                                            (
                                                task,
                                            ) => (
                                                {
                                                    code:
                                                        task.code,

                                                    title:
                                                        task.title,

                                                    category:
                                                        task.category,

                                                    sequence:
                                                        task.sequence,

                                                    is_required:
                                                        task.isRequired,

                                                    requires_evidence:
                                                        task.requiresEvidence,
                                                }
                                            ),
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
                    'OnboardingTemplate creation response was empty.',
                );
            }

            return result.data.data;
        },

        onSuccess: () => {
            void queryClient.invalidateQueries(
                {
                    queryKey:
                        onboardingTemplatesQueryKey,
                },
            );
        },
    });
}
