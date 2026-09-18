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
        name: 'Compensation Assignment Test Tenant',
    },
    workspaces: [],
    current: {
        type: 'TENANT' as const,
        organizational_assignment_id: null,
        organization_id: null,
        organization_unit_id: null,
        label: 'Compensation Assignment Test Tenant',
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
    HrCompensationEmploymentShellPage,
} = await import(
    '@/modules/hr/compensation/HrCompensationEmploymentShellPage'
);

const SAMPLE_EMPLOYMENT_ID =
    '01970000-0000-7000-8000-0000000002aa';

function renderShell(
    state:
        | Record<string, unknown>
        | null,
) {
    apiMockServer.use(
        http.get(
            '*/api/v1/hr/employments/*/compensation-assignments',
            () =>
                HttpResponse.json(
                    {
                        status: 'success',
                        data: [],
                    },
                ),
        ),
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
            '*/api/v1/hr/employments/*/benefit-participations',
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
        http.get(
            '*/api/v1/hr/employments/*/compensation-adjustments',
            () =>
                HttpResponse.json(
                    {
                        status: 'success',
                        data: [],
                    },
                ),
        ),
        http.get(
            '*/api/v1/hr/leave-requests',
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
        http.get(
            '*/api/v1/hr/leave-types',
            () =>
                HttpResponse.json(
                    {
                        status: 'success',
                        data: [],
                    },
                ),
        ),
        http.get(
            '*/api/v1/hr/employments/*/leave-entitlements',
            () =>
                HttpResponse.json(
                    {
                        status: 'success',
                        data: [],
                    },
                ),
        ),
    );

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
                                    `/hr/compensation/employments/${SAMPLE_EMPLOYMENT_ID}`,

                                state,
                            },
                        ]
                    }
                >
                    <Routes>
                        <Route
                            path="/hr/compensation/employments/:employmentId"
                            element={
                                <HrCompensationEmploymentShellPage />
                            }
                        />
                    </Routes>
                </MemoryRouter>
            </QueryClientProvider>
        </ApiClientProvider>,
    );
}

describe(
    'HrCompensationEmploymentShellPage',
    () => {
        it(
            'renders the employee name, status badge, and date range carried via navigation state',
            () => {
                renderShell(
                    {
                        employeeName:
                            'Siti Aminah',

                        employmentStatus:
                            'ACTIVE',

                        employmentStartDate:
                            '2024-01-01',

                        employmentEndDate:
                            null,
                    },
                );

                expect(
                    screen.getByRole(
                        'heading',
                        {
                            name: 'Siti Aminah',
                        },
                    ),
                ).toBeInTheDocument();

                expect(
                    screen.getByText(
                        'Aktif',
                    ),
                ).toBeInTheDocument();

                expect(
                    screen.getByText(
                        (
                            _content,
                            element,
                        ) =>
                            element?.textContent
                            === '2024-01-01 – sekarang',
                    ),
                ).toBeInTheDocument();
            },
        );

        it(
            'falls back gracefully to a generic heading when opened without navigation state',
            () => {
                renderShell(
                    null,
                );

                expect(
                    screen.getByRole(
                        'heading',
                        {
                            name: 'Kompensasi & Benefit Employment',
                        },
                    ),
                ).toBeInTheDocument();

                expect(
                    screen.getByText(
                        (
                            _content,
                            element,
                        ) =>
                            element?.textContent
                            === `ID Employment: ${SAMPLE_EMPLOYMENT_ID}`,
                    ),
                ).toBeInTheDocument();
            },
        );
    },
);
