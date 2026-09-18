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
    ApiComponents,
} from '@/platform/api';
import {
    executeBrowserApiRequest,
    type BrowserApiFailure,
} from '@/platform/api';
import {
    createBrowserMembershipHeaderParams,
} from '@/platform/api/request-context';

export type OnboardingTaskResource =
    ApiComponents['schemas']['OnboardingTaskResource'];

function useMembershipId(): string | null {
    const workspaceState =
        useWorkspaceContextState();

    return workspaceState.status === 'ready'
        ? workspaceState.context.membership.id
        : null;
}

export function useCompleteOnboardingTaskMutation(): UseMutationResult<
    OnboardingTaskResource,
    BrowserApiFailure,
    { taskId: string }
> {
    const apiClient =
        useApiClient();

    const membershipId =
        useMembershipId();

    return useMutation<
        OnboardingTaskResource,
        BrowserApiFailure,
        { taskId: string }
    >({
        mutationFn: async (
            input,
        ) => {
            if (membershipId === null) {
                throw new Error(
                    'useCompleteOnboardingTaskMutation executed without a ready Membership context.',
                );
            }

            const result =
                await executeBrowserApiRequest(
                    apiClient.POST(
                        '/api/v1/hr/onboarding/tasks/{taskId}/complete',
                        {
                            params: {
                                path: {
                                    taskId:
                                        input.taskId,
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
                    'useCompleteOnboardingTaskMutation response was empty.',
                );
            }

            return result.data.data;
        },
    });
}

export function useWaiveOnboardingTaskMutation(): UseMutationResult<
    OnboardingTaskResource,
    BrowserApiFailure,
    { taskId: string }
> {
    const apiClient =
        useApiClient();

    const membershipId =
        useMembershipId();

    return useMutation<
        OnboardingTaskResource,
        BrowserApiFailure,
        { taskId: string }
    >({
        mutationFn: async (
            input,
        ) => {
            if (membershipId === null) {
                throw new Error(
                    'useWaiveOnboardingTaskMutation executed without a ready Membership context.',
                );
            }

            const result =
                await executeBrowserApiRequest(
                    apiClient.POST(
                        '/api/v1/hr/onboarding/tasks/{taskId}/waive',
                        {
                            params: {
                                path: {
                                    taskId:
                                        input.taskId,
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
                    'useWaiveOnboardingTaskMutation response was empty.',
                );
            }

            return result.data.data;
        },
    });
}
