import {
    QueryClient,
    QueryClientProvider,
} from '@tanstack/react-query';
import {
    fireEvent,
    render,
    screen,
    waitFor,
} from '@testing-library/react';
import {
    http,
    HttpResponse,
} from 'msw';
import {
    describe,
    expect,
    it,
    vi,
} from 'vitest';

import {
    ApiClientProvider,
} from '@/app/api/ApiClientProvider';
import {
    createBrowserApiClient,
} from '@/platform/api';
import {
    apiMockServer,
} from '@/test/server';

const READY_TENANT_WORKSPACE_STATE = {
    status: 'ready' as const,
    context: {
        membership: {
            id: '01970000-0000-7000-8000-0000000000aa',
            status: 'ACTIVE' as const,
        },
        tenant: {
            id: '01970000-0000-7000-8000-0000000000bb',
        },
    },
    tenant: {
        name: 'Settings Test Tenant',
    },
    workspaces: [],
    current: {
        type: 'TENANT' as const,
        organizational_assignment_id: null,
        organization_id: null,
        organization_unit_id: null,
        label: 'Settings Test Tenant',
    },
    failure: null,
};

vi.mock(
    '@/app/workspace/WorkspaceContextProvider',
    () => ({
        useWorkspaceContextState: () =>
            READY_TENANT_WORKSPACE_STATE,
    }),
);

const {
    TenantRolesPage,
} = await import(
    '@/modules/settings/roles/TenantRolesPage'
);

function renderRolesPage() {
    const queryClient =
        new QueryClient(
            {
                defaultOptions: {
                    queries: {
                        retry: false,
                    },
                },
            },
        );

    const apiClient =
        createBrowserApiClient();

    render(
        <ApiClientProvider apiClient={apiClient}>
            <QueryClientProvider client={queryClient}>
                <TenantRolesPage />
            </QueryClientProvider>
        </ApiClientProvider>,
    );
}

const SAMPLE_ROLE = {
    id: '01970000-0000-7000-8000-0000000000cc',
    name: 'wali-kelas',
    display_name: 'Wali Kelas',
    description: null,
    permission_count: 1,
    visibility_state: 'active',
};

describe(
    'TenantRolesPage',
    () => {
        it(
            'renders custom roles returned by the tenant roles endpoint',
            async () => {
                apiMockServer.use(
                    http.get(
                        '*/api/v1/core/tenant-roles',
                        () =>
                            HttpResponse.json(
                                {
                                    status: 'success',
                                    data: [SAMPLE_ROLE],
                                },
                            ),
                    ),
                );

                renderRolesPage();

                expect(
                    await screen.findByText(
                        'Wali Kelas',
                    ),
                ).toBeInTheDocument();

                expect(
                    screen.getByText(
                        'Aktif',
                    ),
                ).toBeInTheDocument();
            },
        );

        it(
            'creates a new custom role from the inline form',
            async () => {
                apiMockServer.use(
                    http.get(
                        '*/api/v1/core/tenant-roles',
                        () =>
                            HttpResponse.json(
                                {
                                    status: 'success',
                                    data: [],
                                },
                            ),
                    ),
                    http.post(
                        '*/api/v1/core/tenant-roles',
                        () =>
                            HttpResponse.json(
                                {
                                    status: 'success',
                                    data: {
                                        ...SAMPLE_ROLE,
                                        permissions: [],
                                    },
                                },
                                {
                                    status: 201,
                                },
                            ),
                    ),
                );

                renderRolesPage();

                await screen.findByText(
                    'Belum ada role kustom yang dibuat.',
                );

                fireEvent.change(
                    screen.getByLabelText(
                        'Nama (identifier)',
                    ),
                    {
                        target: {
                            value: 'wali-kelas',
                        },
                    },
                );

                fireEvent.change(
                    screen.getByLabelText(
                        'Nama Tampilan',
                    ),
                    {
                        target: {
                            value: 'Wali Kelas',
                        },
                    },
                );

                fireEvent.click(
                    screen.getByRole(
                        'button',
                        {
                            name: 'Buat Role',
                        },
                    ),
                );

                await waitFor(
                    () => {
                        expect(
                            screen.getByLabelText(
                                'Nama (identifier)',
                            ),
                        ).toHaveValue(
                            '',
                        );
                    },
                );
            },
        );

        it(
            'opens the permission editor and saves a selection',
            async () => {
                apiMockServer.use(
                    http.get(
                        '*/api/v1/core/tenant-roles/assignable-permissions',
                        () =>
                            HttpResponse.json(
                                {
                                    status: 'success',
                                    data: [
                                        {
                                            id: '01970000-0000-7000-8000-0000000000dd',
                                            name: 'academic.class.manage',
                                            display_name: 'Kelola Kelas',
                                            module: 'Academic',
                                        },
                                    ],
                                },
                            ),
                    ),
                    http.get(
                        '*/api/v1/core/tenant-roles',
                        () =>
                            HttpResponse.json(
                                {
                                    status: 'success',
                                    data: [SAMPLE_ROLE],
                                },
                            ),
                    ),
                    http.get(
                        '*/api/v1/core/tenant-roles/:roleId',
                        () =>
                            HttpResponse.json(
                                {
                                    status: 'success',
                                    data: {
                                        ...SAMPLE_ROLE,
                                        permissions: [],
                                    },
                                },
                            ),
                    ),
                    http.put(
                        '*/api/v1/core/tenant-roles/:roleId',
                        () =>
                            HttpResponse.json(
                                {
                                    status: 'success',
                                    data: {
                                        ...SAMPLE_ROLE,
                                        permission_count: 1,
                                        permissions: [
                                            {
                                                id: '01970000-0000-7000-8000-0000000000dd',
                                                name: 'academic.class.manage',
                                                display_name: 'Kelola Kelas',
                                                module: 'Academic',
                                            },
                                        ],
                                    },
                                },
                            ),
                    ),
                );

                renderRolesPage();

                fireEvent.click(
                    await screen.findByRole(
                        'button',
                        {
                            name: 'Kelola Permission',
                        },
                    ),
                );

                const checkbox =
                    await screen.findByRole(
                        'checkbox',
                        {
                            name: 'Kelola Kelas',
                        },
                    );

                fireEvent.click(
                    checkbox,
                );

                fireEvent.click(
                    screen.getByRole(
                        'button',
                        {
                            name: 'Simpan Permission',
                        },
                    ),
                );

                await waitFor(
                    () => {
                        expect(
                            screen.getByRole(
                                'button',
                                {
                                    name: 'Simpan Permission',
                                },
                            ),
                        ).not.toBeDisabled();
                    },
                );
            },
        );
    },
);