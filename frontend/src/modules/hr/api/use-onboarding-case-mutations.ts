import {
    useMutation,
    type UseMutationResult,
} from '@tanstack/react-query';

import {
    useApiClient,
} from '@/app/api/ApiClientProvider';
import {
    useWorkspaceContextState,
} from '@/app/workspace/WorkspaceContextProvider';
import type {
    OnboardingCaseResource,
} from '@/modules/hr/api/use-recruitment-application-mutations';
import {
    executeBrowserApiRequest,
    type BrowserApiFailure,
} from '@/platform/api';
import {
    createBrowserMembershipHeaderParams,
} from '@/platform/api/request-context';

function useMembershipId(): string | null {
    const workspaceState =
        useWorkspaceContextState();

    return workspaceState.status === 'ready'
        ? workspaceState.context.membership.id
        : null;
}

export function useStartOnboardingCaseMutation(): UseMutationResult<
    OnboardingCaseResource,
    BrowserApiFailure,
    { caseId: string }
> {
    const apiClient =
        useApiClient();

    const membershipId =
        useMembershipId();

    return useMutation<
        OnboardingCaseResource,
        BrowserApiFailure,
        { caseId: string }
    >({
        mutationFn: async (
            input,
        ) => {
            if (membershipId === null) {
                throw new Error(
                    'useStartOnboardingCaseMutation executed without a ready Membership context.',
                );
            }

            const result =
                await executeBrowserApiRequest(
                    apiClient.POST(
                        '/api/v1/hr/onboarding/cases/{caseId}/start',
                        {
                            params: {
                                path: {
                                    caseId:
                                        input.caseId,
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
                    'useStartOnboardingCaseMutation response was empty.',
                );
            }

            return result.data.data;
        },
    });
}

export function useCancelOnboardingCaseMutation(): UseMutationResult<
    OnboardingCaseResource,
    BrowserApiFailure,
    { caseId: string; reason: string }
> {
    const apiClient =
        useApiClient();

    const membershipId =
        useMembershipId();

    return useMutation<
        OnboardingCaseResource,
        BrowserApiFailure,
        { caseId: string; reason: string }
    >({
        mutationFn: async (
            input,
        ) => {
            if (membershipId === null) {
                throw new Error(
                    'useCancelOnboardingCaseMutation executed without a ready Membership context.',
                );
            }

            const result =
                await executeBrowserApiRequest(
                    apiClient.POST(
                        '/api/v1/hr/onboarding/cases/{caseId}/cancel',
                        {
                            params: {
                                path: {
                                    caseId:
                                        input.caseId,
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
                    'useCancelOnboardingCaseMutation response was empty.',
                );
            }

            return result.data.data;
        },
    });
}
