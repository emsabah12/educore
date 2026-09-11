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
    Route,
    Routes,
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
        name: 'Organization Units Test Tenant',
    },
    workspaces: [],
    current: {
        type: 'TENANT' as const,
        organizational_assignment_id: null,
        organization_id: null,
        organization_unit_id: null,
        label: 'Organization Units Test Tenant',
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
    OrganizationUnitsPage,
} = await import(
    '@/modules/settings/organizations/OrganizationUnitsPage'
);

const ORGANIZATION_ID =
    '01970000-0000-7000-8000-0000000000gg';

const SAMPLE_ORGANIZATION = {
    id: ORGANIZATION_ID,
    name: 'Kampus Utama',
    code: 'KAMPUS-UTAMA',
    is_active: true,
    created_at: '2026-09-01T08:00:00+00:00',
};

const SAMPLE_UNIT = {
    id: '01970000-0000-7000-8000-0000000000hh',
    organization_id: ORGANIZATION_ID,
    name: 'Fakultas Teknik',
    code: 'FT',
    is_active: true,
    created_at: '2026-09-02T08:00:00+00:00',
};

function renderOrganizationUnitsPage() {
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
                <MemoryRouter
                    initialEntries={
                        [`/settings/organizations/${ORGANIZATION_ID}/units`]
                    }
                >
                    <Routes>
                        <Route
                            path="/settings/organizations/:organizationId/units"
                            element={
                                <OrganizationUnitsPage />
                            }
                        />
                    </Routes>
                </MemoryRouter>
            </QueryClientProvider>
        </ApiClientProvider>,
    );
}

function mockOrganizationsList() {
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
}

describe(
    'OrganizationUnitsPage',
    () => {
        it(
            'renders the organization name and its units',
            async () => {
                mockOrganizationsList();

                apiMockServer.use(
                    http.get(
                        `*/api/v1/core/organizations/${ORGANIZATION_ID}/units`,
                        () =>
                            HttpResponse.json(
                                {
                                    status: 'success',
                                    data: [SAMPLE_UNIT],
                                },
                            ),
                    ),
                );

                renderOrganizationUnitsPage();

                expect(
                    await screen.findByRole(
                        'heading',
                        {
                            name: 'Unit — Kampus Utama',
                        },
                    ),
                ).toBeInTheDocument();

                expect(
                    screen.getByText(
                        'Fakultas Teknik',
                    ),
                ).toBeInTheDocument();

                expect(
                    screen.getByText(
                        'FT',
                    ),
                ).toBeInTheDocument();
            },
        );

        it(
            'shows an empty state when the organization has no units yet',
            async () => {
                mockOrganizationsList();

                apiMockServer.use(
                    http.get(
                        `*/api/v1/core/organizations/${ORGANIZATION_ID}/units`,
                        () =>
                            HttpResponse.json(
                                {
                                    status: 'success',
                                    data: [],
                                },
                            ),
                    ),
                );

                renderOrganizationUnitsPage();

                expect(
                    await screen.findByText(
                        'Belum ada Unit yang dibuat.',
                    ),
                ).toBeInTheDocument();
            },
        );

        it(
            'creates a new unit from the inline form',
            async () => {
                mockOrganizationsList();

                apiMockServer.use(
                    http.get(
                        `*/api/v1/core/organizations/${ORGANIZATION_ID}/units`,
                        () =>
                            HttpResponse.json(
                                {
                                    status: 'success',
                                    data: [],
                                },
                            ),
                    ),
                    http.post(
                        `*/api/v1/core/organizations/${ORGANIZATION_ID}/units`,
                        () =>
                            HttpResponse.json(
                                {
                                    status: 'success',
                                    data: SAMPLE_UNIT,
                                },
                                {
                                    status: 201,
                                },
                            ),
                    ),
                );

                renderOrganizationUnitsPage();

                await screen.findByText(
                    'Belum ada Unit yang dibuat.',
                );

                fireEvent.change(
                    screen.getByLabelText(
                        'Nama Unit',
                    ),
                    {
                        target: {
                            value: 'Fakultas Teknik',
                        },
                    },
                );

                fireEvent.change(
                    screen.getByLabelText(
                        'Kode (opsional)',
                    ),
                    {
                        target: {
                            value: 'FT',
                        },
                    },
                );

                fireEvent.click(
                    screen.getByRole(
                        'button',
                        {
                            name: 'Buat Unit',
                        },
                    ),
                );

                await waitFor(
                    () => {
                        expect(
                            screen.getByLabelText(
                                'Nama Unit',
                            ),
                        ).toHaveValue(
                            '',
                        );
                    },
                );
            },
        );

        it(
            'shows the field-level validation message when code is already taken in this organization',
            async () => {
                mockOrganizationsList();

                apiMockServer.use(
                    http.get(
                        `*/api/v1/core/organizations/${ORGANIZATION_ID}/units`,
                        () =>
                            HttpResponse.json(
                                {
                                    status: 'success',
                                    data: [],
                                },
                            ),
                    ),
                    http.post(
                        `*/api/v1/core/organizations/${ORGANIZATION_ID}/units`,
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

                renderOrganizationUnitsPage();

                await screen.findByText(
                    'Belum ada Unit yang dibuat.',
                );

                fireEvent.change(
                    screen.getByLabelText(
                        'Nama Unit',
                    ),
                    {
                        target: {
                            value: 'Fakultas Teknik',
                        },
                    },
                );

                fireEvent.change(
                    screen.getByLabelText(
                        'Kode (opsional)',
                    ),
                    {
                        target: {
                            value: 'FT',
                        },
                    },
                );

                fireEvent.click(
                    screen.getByRole(
                        'button',
                        {
                            name: 'Buat Unit',
                        },
                    ),
                );

                expect(
                    await screen.findByText(
                        'The code has already been taken.',
                    ),
                ).toBeInTheDocument();
            },
        );

        it(
            'shows a not-found message when the organization does not exist',
            async () => {
                mockOrganizationsList();

                apiMockServer.use(
                    http.get(
                        `*/api/v1/core/organizations/${ORGANIZATION_ID}/units`,
                        () =>
                            HttpResponse.json(
                                {
                                    status: 'error',
                                    code: 'RESOURCE_NOT_FOUND',
                                    message: 'The requested organization was not found.',
                                },
                                {
                                    status: 404,
                                },
                            ),
                    ),
                );

                renderOrganizationUnitsPage();

                expect(
                    await screen.findByText(
                        'Organisasi tidak ditemukan.',
                    ),
                ).toBeInTheDocument();
            },
        );

        it(
            'renders a back link to the organizations list',
            async () => {
                mockOrganizationsList();

                apiMockServer.use(
                    http.get(
                        `*/api/v1/core/organizations/${ORGANIZATION_ID}/units`,
                        () =>
                            HttpResponse.json(
                                {
                                    status: 'success',
                                    data: [],
                                },
                            ),
                    ),
                );

                renderOrganizationUnitsPage();

                expect(
                    await screen.findByRole(
                        'link',
                        {
                            name: '← Kembali ke Daftar Organisasi',
                        },
                    ),
                ).toHaveAttribute(
                    'href',
                    '/settings/organizations',
                );
            },
        );
    },
);
