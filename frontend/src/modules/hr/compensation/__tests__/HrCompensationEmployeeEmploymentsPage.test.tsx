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
        name: 'Compensation Employments Test Tenant',
    },
    workspaces: [],
    current: {
        type: 'TENANT' as const,
        organizational_assignment_id: null,
        organization_id: null,
        organization_unit_id: null,
        label: 'Compensation Employments Test Tenant',
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
    HrCompensationEmployeeEmploymentsPage,
} = await import(
    '@/modules/hr/compensation/HrCompensationEmployeeEmploymentsPage'
);

const SAMPLE_EMPLOYEE_ID =
    '01970000-0000-7000-8000-0000000001aa';

const SAMPLE_ACTIVE_EMPLOYMENT = {
    id: '01970000-0000-7000-8000-0000000002aa',
    tenant_id: '01970000-0000-7000-8000-0000000000ff',
    employee_id: SAMPLE_EMPLOYEE_ID,
    employment_type_id: '01970000-0000-7000-8000-0000000003aa',
    employment_classification_id: null,
    status: 'ACTIVE',
    start_date: '2024-01-01',
    end_date: null,
    cancelled_at: null,
    created_at: '2024-01-01T00:00:00+00:00',
    updated_at: null,
};

const SAMPLE_ENDED_EMPLOYMENT = {
    id: '01970000-0000-7000-8000-0000000004aa',
    tenant_id: '01970000-0000-7000-8000-0000000000ff',
    employee_id: SAMPLE_EMPLOYEE_ID,
    employment_type_id: '01970000-0000-7000-8000-0000000003aa',
    employment_classification_id: null,
    status: 'ENDED',
    start_date: '2020-01-01',
    end_date: '2023-12-31',
    cancelled_at: null,
    created_at: '2020-01-01T00:00:00+00:00',
    updated_at: null,
};

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
                <MemoryRouter
                    initialEntries={
                        [
                            {
                                pathname:
                                    `/hr/compensation/employees/${SAMPLE_EMPLOYEE_ID}/employments`,

                                state: {
                                    employeeName:
                                        'Siti Aminah',
                                },
                            },
                        ]
                    }
                >
                    <Routes>
                        <Route
                            path="/hr/compensation/employees/:employeeId/employments"
                            element={
                                <HrCompensationEmployeeEmploymentsPage />
                            }
                        />
                    </Routes>
                </MemoryRouter>
            </QueryClientProvider>
        </ApiClientProvider>,
    );
}

describe(
    'HrCompensationEmployeeEmploymentsPage',
    () => {
        it(
            'renders employments returned by the tenant-wide employee employments endpoint, keyed to the URL employeeId',
            async () => {
                apiMockServer.use(
                    http.get(
                        `*/api/v1/hr/employees/${SAMPLE_EMPLOYEE_ID}/employments`,
                        ({
                            request,
                        }) => {
                            expect(
                                request.headers.get(
                                    'X-EduCore-Membership-Id',
                                ),
                            ).toBe(
                                READY_TENANT_WORKSPACE_STATE
                                    .context.membership.id,
                            );

                            return HttpResponse.json(
                                {
                                    status: 'success',
                                    data: [
                                        SAMPLE_ACTIVE_EMPLOYMENT,
                                        SAMPLE_ENDED_EMPLOYMENT,
                                    ],
                                    meta: {
                                        current_page: 1,
                                        last_page: 1,
                                        per_page: 15,
                                        total: 2,
                                    },
                                },
                            );
                        },
                    ),
                );

                renderPage();

                expect(
                    await screen.findByText(
                        'Aktif',
                    ),
                ).toBeInTheDocument();

                expect(
                    screen.getByText(
                        'Siti Aminah',
                    ),
                ).toBeInTheDocument();

                expect(
                    screen.getAllByText(
                        'Berakhir',
                    ).length,
                ).toBeGreaterThanOrEqual(
                    2,
                );

                const selectLinks =
                    screen.getAllByRole(
                        'link',
                        {
                            name: 'Pilih',
                        },
                    );

                expect(
                    selectLinks,
                ).toHaveLength(
                    2,
                );

                expect(
                    selectLinks[0],
                ).toHaveAttribute(
                    'href',
                    `/hr/compensation/employments/${SAMPLE_ACTIVE_EMPLOYMENT.id}`,
                );
            },
        );

        it(
            'shows an empty state when the employee has no employment history',
            async () => {
                apiMockServer.use(
                    http.get(
                        `*/api/v1/hr/employees/${SAMPLE_EMPLOYEE_ID}/employments`,
                        () =>
                            HttpResponse.json(
                                {
                                    status: 'success',
                                    data: [],
                                    meta: {
                                        current_page: 1,
                                        last_page: 1,
                                        per_page: 15,
                                        total: 0,
                                    },
                                },
                            ),
                    ),
                );

                renderPage();

                expect(
                    await screen.findByText(
                        'Pegawai ini belum punya riwayat employment.',
                    ),
                ).toBeInTheDocument();
            },
        );
    },
);
