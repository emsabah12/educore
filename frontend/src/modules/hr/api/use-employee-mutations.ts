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

export type WorkspaceEmployeeProvisioningResult =
    ApiComponents['schemas']['WorkspaceEmployeeProvisioningSuccess']['data'];

export interface CreateWorkspaceEmployeeInput {
    readonly nama: string;
    readonly nip: string;
    readonly jabatan: string;
    readonly employment_type_id: string;
}

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

export function useCreateWorkspaceEmployeeMutation(): UseMutationResult<
    WorkspaceEmployeeProvisioningResult,
    BrowserApiFailure,
    CreateWorkspaceEmployeeInput
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
        WorkspaceEmployeeProvisioningResult,
        BrowserApiFailure,
        CreateWorkspaceEmployeeInput
    >({
        mutationFn: async (
            input,
        ) => {
            if (
                membershipId === null
                || organizationalAssignmentId === null
            ) {
                throw new Error(
                    'useCreateWorkspaceEmployeeMutation executed without a ready organizational workspace.',
                );
            }

            const result =
                await executeBrowserApiRequest(
                    apiClient.POST(
                        '/api/v1/hr/workspace/employees',
                        {
                            params: {
                                header:
                                    createBrowserWorkspaceHeaderParams(
                                        {
                                            membershipId,
                                            organizationalAssignmentId,
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
                    'Workspace employee provisioning response was empty.',
                );
            }

            return result.data.data;
        },

        onSuccess: () => {
            /*
             * Invalidate SEMUA halaman/per_page untuk workspace ini
             * (bukan cuma page saat ini) — pegawai baru mungkin
             * muncul di halaman mana pun tergantung urutan sortir
             * backend, jadi partial key match di sini lebih aman
             * daripada menebak halaman spesifik.
             */
            void queryClient.invalidateQueries(
                {
                    queryKey: [
                        'hr',
                        'workforce',
                        'employees',
                        organizationalAssignmentId,
                    ],
                },
            );
        },
    });
}
