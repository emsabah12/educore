import {
    QueryClient,
    QueryClientProvider,
} from '@tanstack/react-query';
import {
    render,
    screen,
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
    },
);