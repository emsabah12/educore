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
import type {
    ApiComponents,
} from '@/platform/api';
import {
    executeBrowserApiRequest,
    type BrowserApiFailure,
} from '@/platform/api';
import {
    createBrowserWorkspaceHeaderParams,
} from '@/platform/api/request-context';

export type EmploymentResource =
    ApiComponents['schemas']['EmploymentResource'];

function useWorkspaceHeaderInputs(): {
    membershipId: string | null;
    organizationalAssignmentId: string | null;
} {
    const workspaceState =
        useWorkspaceContextState();

    const isReady =
        workspaceState.status === 'ready';

    return {
        membershipId:
            isReady
                ? workspaceState.context.membership.id
                : null,

        organizationalAssignmentId:
            isReady
            && workspaceState.current.organizational_assignment_id !== null
                ? workspaceState.current.organizational_assignment_id
                : null,
    };
}

/*
 * All three transitions invalidate the same employee-detail
 * query key shape used by useWorkspaceEmployeeDetailQuery —
 * duplicated here (rather than imported) to avoid a
 * circular dependency between the two hook modules.
 */
function employeeDetailQueryKey(
    organizationalAssignmentId: string | null,
    employeeId: string,
): readonly unknown[] {
    return [
        'hr',
        'workforce',
        'employees',
        organizationalAssignmentId,
        employeeId,
    ];
}

interface TransitionInput {
    readonly employmentId: string;
    readonly employeeId: string;
}

function useEmploymentTransitionMutation(
    path:
        | '/api/v1/hr/workspace/employments/{employmentId}/activate'
        | '/api/v1/hr/workspace/employments/{employmentId}/cancel',
): UseMutationResult<EmploymentResource,
    BrowserApiFailure,
    TransitionInput
> {
    const apiClient =
        useApiClient();

    const {
        membershipId,
        organizationalAssignmentId,
    } =
        useWorkspaceHeaderInputs();

    const queryClient =
        useQueryClient();

    return useMutation<EmploymentResource,
        BrowserApiFailure,
        TransitionInput
    >({
        mutationFn: async ({
            employmentId,
        }) => {
            if (
                membershipId === null
                || organizationalAssignmentId === null
            ) {
                throw new Error(
                    'Employment transition mutation executed without a ready organizational workspace.',
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
                                },

                                header:
                                    createBrowserWorkspaceHeaderParams(
                                        {
                                            membershipId,
                                            organizationalAssignmentId,
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
                    'Employment transition response was empty.',
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
                        employeeDetailQueryKey(
                            organizationalAssignmentId,
                            variables.employeeId,
                        ),
                },
            );
        },
    });
}

export function useActivateEmploymentMutation(): UseMutationResult<EmploymentResource,
    BrowserApiFailure,
    TransitionInput
> {
    return useEmploymentTransitionMutation(
        '/api/v1/hr/workspace/employments/{employmentId}/activate',
    );
}

export function useCancelEmploymentMutation(): UseMutationResult<EmploymentResource,
    BrowserApiFailure,
    TransitionInput
> {
    return useEmploymentTransitionMutation(
        '/api/v1/hr/workspace/employments/{employmentId}/cancel',
    );
}

export interface EndEmploymentInput extends TransitionInput {
    readonly endDate: string;
}

export function useEndEmploymentMutation(): UseMutationResult<EmploymentResource,
    BrowserApiFailure,
    EndEmploymentInput
> {
    const apiClient =
        useApiClient();

    const {
        membershipId,
        organizationalAssignmentId,
    } =
        useWorkspaceHeaderInputs();

    const queryClient =
        useQueryClient();

    return useMutation<EmploymentResource,
        BrowserApiFailure,
        EndEmploymentInput
    >({
        mutationFn: async ({
            employmentId,
            endDate,
        }) => {
            if (
                membershipId === null
                || organizationalAssignmentId === null
            ) {
                throw new Error(
                    'useEndEmploymentMutation executed without a ready organizational workspace.',
                );
            }

            const result =
                await executeBrowserApiRequest(
                    apiClient.POST(
                        '/api/v1/hr/workspace/employments/{employmentId}/end',
                        {
                            params: {
                                path: {
                                    employmentId,
                                },

                                header:
                                    createBrowserWorkspaceHeaderParams(
                                        {
                                            membershipId,
                                            organizationalAssignmentId,
                                        },
                                    ),
                            },

                            body: {
                                end_date:
                                    endDate,
                            },
                        },
                    ),
                );

            if (! result.ok) {
                throw result;
            }

            if (result.data === undefined) {
                throw new Error(
                    'End Employment response was empty.',
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
                        employeeDetailQueryKey(
                            organizationalAssignmentId,
                            variables.employeeId,
                        ),
                },
            );
        },
    });
}

export interface CreateEmploymentInput {
    readonly employeeId: string;
    readonly startDate: string;
    /*
     * employment_classification_id still has no catalog-listing
     * endpoint to power a picker, so it stays unexposed here —
     * only employment_type_id is wired up now that
     * useEmploymentTypesQuery exists.
     */
    readonly employmentTypeId?: string;
}

export function useCreateEmploymentMutation(): UseMutationResult<
    EmploymentResource,
    BrowserApiFailure,
    CreateEmploymentInput
> {
    const apiClient =
        useApiClient();

    const {
        membershipId,
        organizationalAssignmentId,
    } =
        useWorkspaceHeaderInputs();

    const queryClient =
        useQueryClient();

    return useMutation<
        EmploymentResource,
        BrowserApiFailure,
        CreateEmploymentInput
    >({
        mutationFn: async ({
            employeeId,
            startDate,
            employmentTypeId,
        }) => {
            if (
                membershipId === null
                || organizationalAssignmentId === null
            ) {
                throw new Error(
                    'useCreateEmploymentMutation executed without a ready organizational workspace.',
                );
            }

            const result =
                await executeBrowserApiRequest(
                    apiClient.POST(
                        '/api/v1/hr/workspace/employees/{employeeId}/employments',
                        {
                            params: {
                                path: {
                                    employeeId,
                                },

                                header:
                                    createBrowserWorkspaceHeaderParams(
                                        {
                                            membershipId,
                                            organizationalAssignmentId,
                                        },
                                    ),
                            },

                            body: {
                                start_date:
                                    startDate,

                                employment_type_id:
                                    employmentTypeId
                                    ?? null,
                            },
                        },
                    ),
                );

            if (! result.ok) {
                throw result;
            }

            if (result.data === undefined) {
                throw new Error(
                    'Create Employment response was empty.',
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
                        employeeDetailQueryKey(
                            organizationalAssignmentId,
                            variables.employeeId,
                        ),
                },
            );
        },
    });
}