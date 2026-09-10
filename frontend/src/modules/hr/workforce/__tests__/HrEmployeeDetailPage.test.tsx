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

const READY_ORGANIZATIONAL_WORKSPACE_STATE = {
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
        name: 'Workforce Detail Test Tenant',
    },
    workspaces: [],
    current: {
        type: 'ORGANIZATION' as const,
        organizational_assignment_id:
            '01970000-0000-7000-8000-0000000000cc',
        organization_id:
            '01970000-0000-7000-8000-0000000000dd',
        organization_unit_id: null,
        label: 'Workforce Detail Test Organization',
    },
    failure: null,
};

vi.mock(
    '@/app/workspace/WorkspaceContextProvider',
    () => ({
        useWorkspaceContextState: () =>
            READY_ORGANIZATIONAL_WORKSPACE_STATE,
    }),
);

const {
    HrEmployeeDetailPage,
} = await import(
    '@/modules/hr/workforce/HrEmployeeDetailPage'
);

const EMPLOYEE_ID =
    '01970000-0000-7000-8000-0000000000ee';

function renderDetailPage() {
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
                        [`/hr/workforce/${EMPLOYEE_ID}`]
                    }
                >
                    <Routes>
                        <Route
                            path="/hr/workforce/:employeeId"
                            element={
                                <HrEmployeeDetailPage />
                            }
                        />
                    </Routes>
                </MemoryRouter>
            </QueryClientProvider>
        </ApiClientProvider>,
    );
}

describe(
    'HrEmployeeDetailPage',
    () => {
        it(
            'renders employee detail with employment history',
            async () => {
                apiMockServer.use(
                    http.get(
                        `*/api/v1/hr/workspace/employees/${EMPLOYEE_ID}`,
                        () =>
                            HttpResponse.json(
                                {
                                    status: 'success',
                                    data: {
                                        id: EMPLOYEE_ID,
                                        tenant_id:
                                            '01970000-0000-7000-8000-0000000000bb',
                                        membership_id:
                                            '01970000-0000-7000-8000-0000000000ff',
                                        nip: 'NIP-001',
                                        jabatan: 'GURU',
                                        nama: 'Budi Santoso',
                                        created_at:
                                            '2026-01-01T00:00:00Z',
                                        employments: [
                                            {
                                                id: '01970000-0000-7000-8000-000000001111',
                                                employment_type: 'Tetap',
                                                status: 'ACTIVE',
                                                start_date: '2026-01-01',
                                                end_date: null,
                                            },
                                        ],
                                    },
                                },
                            ),
                    ),
                );

                renderDetailPage();

                expect(
                    await screen.findByText(
                        'Budi Santoso',
                    ),
                ).toBeInTheDocument();

                expect(
                    screen.getByText(
                        /NIP-001/,
                    ),
                ).toBeInTheDocument();

                expect(
                    screen.getByText(
                        'Tetap',
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
            'shows an empty state when the employee has no employment history',
            async () => {
                apiMockServer.use(
                    http.get(
                        `*/api/v1/hr/workspace/employees/${EMPLOYEE_ID}`,
                        () =>
                            HttpResponse.json(
                                {
                                    status: 'success',
                                    data: {
                                        id: EMPLOYEE_ID,
                                        tenant_id:
                                            '01970000-0000-7000-8000-0000000000bb',
                                        membership_id:
                                            '01970000-0000-7000-8000-0000000000ff',
                                        nip: null,
                                        jabatan: 'STAFF',
                                        nama: 'Siti Aminah',
                                        created_at:
                                            '2026-01-01T00:00:00Z',
                                        employments: [],
                                    },
                                },
                            ),
                    ),
                );

                renderDetailPage();

                expect(
                    await screen.findByText(
                        'Belum ada riwayat employment.',
                    ),
                ).toBeInTheDocument();
            },
        );

        it(
            'shows a not-found message when the employee is outside the workspace',
            async () => {
                apiMockServer.use(
                    http.get(
                        `*/api/v1/hr/workspace/employees/${EMPLOYEE_ID}`,
                        () =>
                            HttpResponse.json(
                                {
                                    status: 'error',
                                    code: 'RESOURCE_NOT_FOUND',
                                    message: 'Not found.',
                                },
                                {
                                    status: 404,
                                },
                            ),
                    ),
                );

                renderDetailPage();

                expect(
                    await screen.findByRole(
                        'alert',
                    ),
                ).toHaveTextContent(
                    'di luar workspace Anda',
                );
            },
        );

        it(
            'activates a PLANNED employment',
            async () => {
                const EMPLOYMENT_ID =
                    '01970000-0000-7000-8000-000000001111';

                let currentStatus = 'PLANNED';

                apiMockServer.use(
                    http.get(
                        `*/api/v1/hr/workspace/employees/${EMPLOYEE_ID}`,
                        () =>
                            HttpResponse.json(
                                {
                                    status: 'success',
                                    data: {
                                        id: EMPLOYEE_ID,
                                        tenant_id:
                                            '01970000-0000-7000-8000-0000000000bb',
                                        membership_id:
                                            '01970000-0000-7000-8000-0000000000ff',
                                        nip: 'NIP-001',
                                        jabatan: 'GURU',
                                        nama: 'Budi Santoso',
                                        created_at:
                                            '2026-01-01T00:00:00Z',
                                        employments: [
                                            {
                                                id: EMPLOYMENT_ID,
                                                employment_type: 'Tetap',
                                                status: currentStatus,
                                                start_date: '2026-01-01',
                                                end_date: null,
                                            },
                                        ],
                                    },
                                },
                            ),
                    ),
                    http.post(
                        `*/api/v1/hr/workspace/employments/${EMPLOYMENT_ID}/activate`,
                        () => {
                            currentStatus = 'ACTIVE';

                            return HttpResponse.json(
                                {
                                    status: 'success',
                                    message: 'Activated Employment.',
                                    data: {
                                        id: EMPLOYMENT_ID,
                                        tenant_id:
                                            '01970000-0000-7000-8000-0000000000bb',
                                        employee_id: EMPLOYEE_ID,
                                        employment_type_id: null,
                                        employment_classification_id: null,
                                        status: 'ACTIVE',
                                        start_date: '2026-01-01',
                                        end_date: null,
                                        cancelled_at: null,
                                        created_at:
                                            '2026-01-01T00:00:00Z',
                                    },
                                },
                            );
                        },
                    ),
                );

                renderDetailPage();

                fireEvent.click(
                    await screen.findByRole(
                        'button',
                        {
                            name: 'Aktifkan',
                        },
                    ),
                );

                await waitFor(
                    () => {
                        expect(
                            screen.queryByRole(
                                'button',
                                {
                                    name: 'Aktifkan',
                                },
                            ),
                        ).not.toBeInTheDocument();
                    },
                );

                expect(
                    await screen.findByRole(
                        'button',
                        {
                            name: 'Akhiri',
                        },
                    ),
                ).toBeInTheDocument();
            },
        );

        it(
            'shows a conflict message when activation fails because the employment already changed',
            async () => {
                const EMPLOYMENT_ID =
                    '01970000-0000-7000-8000-000000001111';

                apiMockServer.use(
                    http.get(
                        `*/api/v1/hr/workspace/employees/${EMPLOYEE_ID}`,
                        () =>
                            HttpResponse.json(
                                {
                                    status: 'success',
                                    data: {
                                        id: EMPLOYEE_ID,
                                        tenant_id:
                                            '01970000-0000-7000-8000-0000000000bb',
                                        membership_id:
                                            '01970000-0000-7000-8000-0000000000ff',
                                        nip: 'NIP-001',
                                        jabatan: 'GURU',
                                        nama: 'Budi Santoso',
                                        created_at:
                                            '2026-01-01T00:00:00Z',
                                        employments: [
                                            {
                                                id: EMPLOYMENT_ID,
                                                employment_type: 'Tetap',
                                                status: 'PLANNED',
                                                start_date: '2026-01-01',
                                                end_date: null,
                                            },
                                        ],
                                    },
                                },
                            ),
                    ),
                    http.post(
                        `*/api/v1/hr/workspace/employments/${EMPLOYMENT_ID}/activate`,
                        () =>
                            HttpResponse.json(
                                {
                                    status: 'error',
                                    code: 'EMPLOYMENT_LIFECYCLE_CONFLICT',
                                    message: 'Employment is not PLANNED.',
                                },
                                {
                                    status: 409,
                                },
                            ),
                    ),
                );

                renderDetailPage();

                fireEvent.click(
                    await screen.findByRole(
                        'button',
                        {
                            name: 'Aktifkan',
                        },
                    ),
                );

                expect(
                    await screen.findByRole(
                        'alert',
                    ),
                ).toHaveTextContent(
                    'sudah berubah',
                );
            },
        );

        it(
            'ends an ACTIVE employment after entering an end date',
            async () => {
                const EMPLOYMENT_ID =
                    '01970000-0000-7000-8000-000000001111';

                apiMockServer.use(
                    http.get(
                        `*/api/v1/hr/workspace/employees/${EMPLOYEE_ID}`,
                        () =>
                            HttpResponse.json(
                                {
                                    status: 'success',
                                    data: {
                                        id: EMPLOYEE_ID,
                                        tenant_id:
                                            '01970000-0000-7000-8000-0000000000bb',
                                        membership_id:
                                            '01970000-0000-7000-8000-0000000000ff',
                                        nip: 'NIP-001',
                                        jabatan: 'GURU',
                                        nama: 'Budi Santoso',
                                        created_at:
                                            '2026-01-01T00:00:00Z',
                                        employments: [
                                            {
                                                id: EMPLOYMENT_ID,
                                                employment_type: 'Tetap',
                                                status: 'ACTIVE',
                                                start_date: '2026-01-01',
                                                end_date: null,
                                            },
                                        ],
                                    },
                                },
                            ),
                    ),
                    http.post(
                        `*/api/v1/hr/workspace/employments/${EMPLOYMENT_ID}/end`,
                        () =>
                            HttpResponse.json(
                                {
                                    status: 'success',
                                    message: 'Ended Employment.',
                                    data: {
                                        id: EMPLOYMENT_ID,
                                        tenant_id:
                                            '01970000-0000-7000-8000-0000000000bb',
                                        employee_id: EMPLOYEE_ID,
                                        employment_type_id: null,
                                        employment_classification_id: null,
                                        status: 'ENDED',
                                        start_date: '2026-01-01',
                                        end_date: '2026-06-30',
                                        cancelled_at: null,
                                        created_at:
                                            '2026-01-01T00:00:00Z',
                                    },
                                },
                            ),
                    ),
                );

                renderDetailPage();

                fireEvent.click(
                    await screen.findByRole(
                        'button',
                        {
                            name: 'Akhiri',
                        },
                    ),
                );

                fireEvent.change(
                    screen.getByLabelText(
                        /Tanggal akhir/,
                    ),
                    {
                        target: {
                            value: '2026-06-30',
                        },
                    },
                );

                fireEvent.click(
                    screen.getByRole(
                        'button',
                        {
                            name: 'Konfirmasi',
                        },
                    ),
                );

                await waitFor(
                    () => {
                        expect(
                            screen.queryByRole(
                                'button',
                                {
                                    name: 'Konfirmasi',
                                },
                            ),
                        ).not.toBeInTheDocument();
                    },
                );
            },
        );

        it(
            'creates a new PLANNED employment episode from the inline form',
            async () => {
                let latestEmployments: unknown[] = [
                    {
                        id: '01970000-0000-7000-8000-000000002222',
                        employment_type: 'Tetap',
                        status: 'ENDED',
                        start_date: '2020-01-01',
                        end_date: '2025-12-31',
                    },
                ];

                apiMockServer.use(
                    http.get(
                        `*/api/v1/hr/workspace/employees/${EMPLOYEE_ID}`,
                        () =>
                            HttpResponse.json(
                                {
                                    status: 'success',
                                    data: {
                                        id: EMPLOYEE_ID,
                                        tenant_id:
                                            '01970000-0000-7000-8000-0000000000bb',
                                        membership_id:
                                            '01970000-0000-7000-8000-0000000000ff',
                                        nip: 'NIP-001',
                                        jabatan: 'GURU',
                                        nama: 'Budi Santoso',
                                        created_at:
                                            '2026-01-01T00:00:00Z',
                                        employments: latestEmployments,
                                    },
                                },
                            ),
                    ),
                    http.post(
                        `*/api/v1/hr/workspace/employees/${EMPLOYEE_ID}/employments`,
                        () => {
                            latestEmployments = [
                                ...latestEmployments,
                                {
                                    id: '01970000-0000-7000-8000-000000003333',
                                    employment_type: null,
                                    status: 'PLANNED',
                                    start_date: '2026-07-01',
                                    end_date: null,
                                },
                            ];

                            return HttpResponse.json(
                                {
                                    status: 'success',
                                    message: 'Employment created with PLANNED status.',
                                    data: {
                                        id: '01970000-0000-7000-8000-000000003333',
                                        tenant_id:
                                            '01970000-0000-7000-8000-0000000000bb',
                                        employee_id: EMPLOYEE_ID,
                                        employment_type_id: null,
                                        employment_classification_id: null,
                                        status: 'PLANNED',
                                        start_date: '2026-07-01',
                                        end_date: null,
                                        cancelled_at: null,
                                        created_at:
                                            '2026-07-01T00:00:00Z',
                                    },
                                },
                                {
                                    status: 201,
                                },
                            );
                        },
                    ),
                );

                renderDetailPage();

                fireEvent.click(
                    await screen.findByRole(
                        'button',
                        {
                            name: '+ Tambah Employment',
                        },
                    ),
                );

                fireEvent.change(
                    screen.getByLabelText(
                        'Tanggal Mulai',
                    ),
                    {
                        target: {
                            value: '2026-07-01',
                        },
                    },
                );

                fireEvent.click(
                    screen.getByRole(
                        'button',
                        {
                            name: 'Buat',
                        },
                    ),
                );

                await waitFor(
                    () => {
                        expect(
                            screen.queryByRole(
                                'button',
                                {
                                    name: 'Buat',
                                },
                            ),
                        ).not.toBeInTheDocument();
                    },
                );

                expect(
                    await screen.findByRole(
                        'button',
                        {
                            name: '+ Tambah Employment',
                        },
                    ),
                ).toBeInTheDocument();
            },
        );
    },
);