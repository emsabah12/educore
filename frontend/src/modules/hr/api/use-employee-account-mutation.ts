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
import {
    executeBrowserApiRequest,
    type BrowserApiFailure,
} from '@/platform/api';
import {
    createBrowserWorkspaceHeaderParams,
} from '@/platform/api/request-context';

export interface CreateEmployeeAccountInput {
    readonly employeeId: string;
    readonly email: string;
}

export interface CreateEmployeeAccountResult {
    readonly user_id: string;
    readonly email: string;
    readonly generated_password: string;
}

/*
 * §Pengaturan Akun Pegawai — SENGAJA TIDAK ada query hook pendamping
 * untuk "baca ulang password" — generated_password hanya pernah
 * muncul sekali, langsung dari hasil mutation ini. Tidak di-cache
 * oleh React Query (mutation result bukan query state), tidak
 * disimpan ke storage apa pun oleh komponen pemanggil.
 */
export function useCreateEmployeeAccountMutation(): UseMutationResult<
    CreateEmployeeAccountResult,
    BrowserApiFailure,
    CreateEmployeeAccountInput
> {
    const apiClient =
        useApiClient();

    const workspaceState =
        useWorkspaceContextState();

    return useMutation<
        CreateEmployeeAccountResult,
        BrowserApiFailure,
        CreateEmployeeAccountInput
    >({
        mutationFn: async (
            input,
        ) => {
            if (workspaceState.status !== 'ready') {
                throw new Error(
                    'useCreateEmployeeAccountMutation executed without a ready organizational workspace.',
                );
            }

            const membershipId =
                workspaceState.context.membership.id;

            const organizationalAssignmentId =
                workspaceState.current.organizational_assignment_id;

            if (organizationalAssignmentId === null) {
                throw new Error(
                    'useCreateEmployeeAccountMutation executed without a ready organizational workspace.',
                );
            }

            const result =
                await executeBrowserApiRequest(
                    apiClient.POST(
                        '/api/v1/hr/workspace/employees/{employeeId}/create-account',
                        {
                            params: {
                                path: {
                                    employeeId:
                                        input.employeeId,
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
                                email:
                                    input.email,
                            },
                        },
                    ),
                );

            if (! result.ok) {
                throw result;
            }

            if (result.data === undefined) {
                throw new Error(
                    'Employee account creation response was empty.',
                );
            }

            return result.data.data;
        },
    });
}
