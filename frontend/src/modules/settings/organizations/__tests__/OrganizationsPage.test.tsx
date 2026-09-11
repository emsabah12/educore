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
    MemoryRouter,
} from 'react-router';
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
            id: '01970000-0000-7000-8000-0000000000ee',
            status: 'ACTIVE' as const,
        },
        tenant: {
            id: '01970000-0000-7000-8000-0000000000ff',
        },
    },
    tenant: {
        name: 'Organizations Test Tenant',
    },
    workspaces: [],
    current: {
        type: 'TENANT' as const,
        organizational_assignment_id: null,
        organization_id: null,
        organization_unit_id: null,
        label: 'Organizations Test Tenant',
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
    OrganizationsPage,
} = await import(
    '@/modules/settings/organizations/OrganizationsPage'
);

function renderOrganizationsPage() {
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
                <MemoryRouter>
                    <OrganizationsPage />
                </MemoryRouter>
            </QueryClientProvider>
        </ApiClientProvider>,
    );
}

const SAMPLE_ORGANIZATION = {
    id: '01970000-0000-7000-8000-0000000000gg',
    name: 'Kampus Utama',
    code: 'KAMPUS-UTAMA',
    is_active: true,
    created_at: '2026-09-01T08:00:00+00:00',
};

describe(
    'OrganizationsPage',
    () => {
        it(
            'renders organizations returned by the organizations endpoint',
            async () => {
                apiMockServer.use(
                    http.get(
                        '*/api/v1/core/organizations',
                        () =>
                            HttpResponse.json(
                                {
                                    status: 'success',
                                    data: [SAMPLE_ORGANIZATION],
                                },
                            ),
                    ),
                );

                renderOrganizationsPage();

                expect(
                    await screen.findByText(
                        'Kampus Utama',
                    ),
                ).toBeInTheDocument();

                expect(
                    screen.getByText(
                        'KAMPUS-UTAMA',
                    ),
                ).toBeInTheDocument();

                expect(
                    screen.getByText(
                        'Aktif',
                    ),
                ).toBeInTheDocument();

                expect(
                    screen.getByRole(
                        'link',
                        {
                            name: 'Kelola Unit',
                        },
                    ),
                ).toHaveAttribute(
                    'href',
                    `/settings/organizations/${SAMPLE_ORGANIZATION.id}/units`,
                );

                expect(
                    screen.getByRole(
                        'link',
                        {
                            name: 'Kelola Anggota',
                        },
                    ),
                ).toHaveAttribute(
                    'href',
                    `/settings/organizations/${SAMPLE_ORGANIZATION.id}/members`,
                );
            },
        );

        it(
            'creates a new organization from the inline form',
            async () => {
                apiMockServer.use(
                    http.get(
                        '*/api/v1/core/organizations',
                        () =>
                            HttpResponse.json(
                                {
                                    status: 'success',
                                    data: [],
                                },
                            ),
                    ),
                    http.post(
                        '*/api/v1/core/organizations',
                        () =>
                            HttpResponse.json(
                                {
                                    status: 'success',
                                    data: SAMPLE_ORGANIZATION,
                                },
                                {
                                    status: 201,
                                },
                            ),
                    ),
                );

                renderOrganizationsPage();

                await screen.findByText(
                    'Belum ada Organisasi yang dibuat.',
                );

                fireEvent.change(
                    screen.getByLabelText(
                        'Nama Organisasi',
                    ),
                    {
                        target: {
                            value: 'Kampus Utama',
                        },
                    },
                );

                fireEvent.change(
                    screen.getByLabelText(
                        'Kode (opsional)',
                    ),
                    {
                        target: {
                            value: 'KAMPUS-UTAMA',
                        },
                    },
                );

                fireEvent.click(
                    screen.getByRole(
                        'button',
                        {
                            name: 'Buat Organisasi',
                        },
                    ),
                );

                await waitFor(
                    () => {
                        expect(
                            screen.getByLabelText(
                                'Nama Organisasi',
                            ),
                        ).toHaveValue(
                            '',
                        );
                    },
                );
            },
        );

        it(
            'shows the field-level validation message when code is already taken',
            async () => {
                apiMockServer.use(
                    http.get(
                        '*/api/v1/core/organizations',
                        () =>
                            HttpResponse.json(
                                {
                                    status: 'success',
                                    data: [],
                                },
                            ),
                    ),
                    http.post(
                        '*/api/v1/core/organizations',
                        () =>
                            HttpResponse.json(
                                {
                                    status: 'error',
                                    code: 'VALIDATION_FAILED',
                                    message: 'The submitted data is invalid.',
                                    errors: {
                                        code: [
                                            'The code has already been taken.',
                                        ],
                                    },
                                },
                                {
                                    status: 422,
                                },
                            ),
                    ),
                );

                renderOrganizationsPage();

                await screen.findByText(
                    'Belum ada Organisasi yang dibuat.',
                );

                fireEvent.change(
                    screen.getByLabelText(
                        'Nama Organisasi',
                    ),
                    {
                        target: {
                            value: 'Kampus Utama',
                        },
                    },
                );

                fireEvent.change(
                    screen.getByLabelText(
                        'Kode (opsional)',
                    ),
                    {
                        target: {
                            value: 'KAMPUS-UTAMA',
                        },
                    },
                );

                fireEvent.click(
                    screen.getByRole(
                        'button',
                        {
                            name: 'Buat Organisasi',
                        },
                    ),
                );

                expect(
                    await screen.findByText(
                        'The code has already been taken.',
                    ),
                ).toBeInTheDocument();

                /*
                 * The field-level message must not be duplicated
                 * by the generic fallback error paragraph.
                 */
                expect(
                    screen.queryByText(
                        'Gagal membuat Organisasi. Coba lagi.',
                    ),
                ).not.toBeInTheDocument();
            },
        );
    },
);