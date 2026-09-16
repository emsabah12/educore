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
        name: 'Compensation Catalog Test Tenant',
    },
    workspaces: [],
    current: {
        type: 'TENANT' as const,
        organizational_assignment_id: null,
        organization_id: null,
        organization_unit_id: null,
        label: 'Compensation Catalog Test Tenant',
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
    HrCompensationCatalogPage,
} = await import(
    '@/modules/hr/compensation/HrCompensationCatalogPage'
);

function renderPage() {
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
                    <HrCompensationCatalogPage />
                </MemoryRouter>
            </QueryClientProvider>
        </ApiClientProvider>,
    );
}

describe(
    'HrCompensationCatalogPage',
    () => {
        it(
            'renders existing catalog entries for both Compensation Component and Benefit Program',
            async () => {
                apiMockServer.use(
                    http.get(
                        '*/api/v1/hr/compensation/components',
                        () =>
                            HttpResponse.json(
                                {
                                    status: 'success',
                                    data: [
                                        {
                                            id: '01970000-0000-7000-8000-0000000010aa',
                                            tenant_id: '01970000-0000-7000-8000-0000000000ff',
                                            code: 'BASE_SALARY',
                                            name: 'Gaji Pokok Guru',
                                            category: 'BASE_PAY',
                                            value_mode: 'FIXED_AMOUNT',
                                            unit_code: null,
                                            periodicity: 'MONTHLY',
                                            description: null,
                                            is_active: true,
                                            created_at: '2026-01-01T00:00:00+00:00',
                                            updated_at: '2026-01-01T00:00:00+00:00',
                                        },
                                    ],
                                },
                            ),
                    ),
                    http.get(
                        '*/api/v1/hr/benefits/programs',
                        () =>
                            HttpResponse.json(
                                {
                                    status: 'success',
                                    data: [
                                        {
                                            id: '01970000-0000-7000-8000-0000000011aa',
                                            tenant_id: '01970000-0000-7000-8000-0000000000ff',
                                            code: 'BPJS_KESEHATAN',
                                            name: 'BPJS Kesehatan',
                                            category: 'STATUTORY',
                                            beneficiary_scope: 'EMPLOYEE',
                                            payroll_relevance: 'NONE',
                                            description: null,
                                            is_active: true,
                                            created_at: '2026-01-01T00:00:00+00:00',
                                            updated_at: '2026-01-01T00:00:00+00:00',
                                        },
                                    ],
                                },
                            ),
                    ),
                );

                renderPage();

                expect(
                    await screen.findByText(
                        'Gaji Pokok Guru',
                    ),
                ).toBeInTheDocument();

                expect(
                    screen.getByText(
                        'BPJS Kesehatan',
                    ),
                ).toBeInTheDocument();
            },
        );

        it(
            'creates a new Compensation Component from the inline form',
            async () => {
                let latestComponents: unknown[] = [];

                apiMockServer.use(
                    http.get(
                        '*/api/v1/hr/compensation/components',
                        () =>
                            HttpResponse.json(
                                {
                                    status: 'success',
                                    data: latestComponents,
                                },
                            ),
                    ),
                    http.get(
                        '*/api/v1/hr/benefits/programs',
                        () =>
                            HttpResponse.json(
                                {
                                    status: 'success',
                                    data: [],
                                },
                            ),
                    ),
                    http.post(
                        '*/api/v1/hr/compensation/components',
                        async ({
                            request,
                        }) => {
                            const body =
                                await request.json() as Record<string, unknown>;

                            expect(
                                body,
                            ).toEqual(
                                {
                                    code: 'TEACHING_HOUR_RATE',
                                    name: 'Tarif Jam Mengajar',
                                    category: 'RATE',
                                    value_mode: 'RATE_PER_UNIT',
                                    unit_code: 'JAM',
                                    periodicity: 'PER_UNIT',
                                    description: null,
                                },
                            );

                            const created = {
                                id: '01970000-0000-7000-8000-0000000012aa',
                                tenant_id: '01970000-0000-7000-8000-0000000000ff',
                                ...body,
                                is_active: true,
                                created_at: '2026-01-01T00:00:00+00:00',
                                updated_at: '2026-01-01T00:00:00+00:00',
                            };

                            latestComponents = [
                                created,
                            ];

                            return HttpResponse.json(
                                {
                                    status: 'success',
                                    data: created,
                                },
                                {
                                    status: 201,
                                },
                            );
                        },
                    ),
                );

                renderPage();

                await screen.findByText(
                    'Belum ada komponen kompensasi.',
                );

                fireEvent.change(
                    screen.getByLabelText(
                        'Kode',
                        {
                            selector: '#catalog-component-code',
                        },
                    ),
                    {
                        target: {
                            value: 'TEACHING_HOUR_RATE',
                        },
                    },
                );

                fireEvent.change(
                    screen.getByLabelText(
                        'Nama',
                        {
                            selector: '#catalog-component-name',
                        },
                    ),
                    {
                        target: {
                            value: 'Tarif Jam Mengajar',
                        },
                    },
                );

                fireEvent.change(
                    screen.getByLabelText(
                        'Kategori',
                        {
                            selector: '#catalog-component-category',
                        },
                    ),
                    {
                        target: {
                            value: 'RATE',
                        },
                    },
                );

                fireEvent.change(
                    screen.getByLabelText(
                        'Mode Nilai',
                    ),
                    {
                        target: {
                            value: 'RATE_PER_UNIT',
                        },
                    },
                );

                fireEvent.change(
                    screen.getByLabelText(
                        'Kode Unit',
                    ),
                    {
                        target: {
                            value: 'JAM',
                        },
                    },
                );

                fireEvent.change(
                    screen.getByLabelText(
                        'Periodisitas',
                    ),
                    {
                        target: {
                            value: 'PER_UNIT',
                        },
                    },
                );

                fireEvent.click(
                    screen.getByRole(
                        'button',
                        {
                            name: 'Tambah Komponen',
                        },
                    ),
                );

                await waitFor(
                    () => {
                        expect(
                            screen.getByText(
                                'Tarif Jam Mengajar',
                            ),
                        ).toBeInTheDocument();
                    },
                );
            },
        );

        it(
            'shows a friendly message when the Benefit Program code is already taken',
            async () => {
                apiMockServer.use(
                    http.get(
                        '*/api/v1/hr/compensation/components',
                        () =>
                            HttpResponse.json(
                                {
                                    status: 'success',
                                    data: [],
                                },
                            ),
                    ),
                    http.get(
                        '*/api/v1/hr/benefits/programs',
                        () =>
                            HttpResponse.json(
                                {
                                    status: 'success',
                                    data: [],
                                },
                            ),
                    ),
                    http.post(
                        '*/api/v1/hr/benefits/programs',
                        () =>
                            HttpResponse.json(
                                {
                                    status: 'error',
                                    code: 'VALIDATION_FAILED',
                                    message: 'The code has already been taken.',
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

                renderPage();

                await screen.findByText(
                    'Belum ada program benefit.',
                );

                fireEvent.change(
                    screen.getByLabelText(
                        'Kode',
                        {
                            selector: '#catalog-program-code',
                        },
                    ),
                    {
                        target: {
                            value: 'BPJS_KESEHATAN',
                        },
                    },
                );

                fireEvent.change(
                    screen.getByLabelText(
                        'Nama',
                        {
                            selector: '#catalog-program-name',
                        },
                    ),
                    {
                        target: {
                            value: 'BPJS Kesehatan',
                        },
                    },
                );

                fireEvent.click(
                    screen.getByRole(
                        'button',
                        {
                            name: 'Tambah Program',
                        },
                    ),
                );

                expect(
                    await screen.findByRole(
                        'alert',
                    ),
                ).toHaveTextContent(
                    'Kode ini sudah dipakai program lain.',
                );
            },
        );
    },
);
