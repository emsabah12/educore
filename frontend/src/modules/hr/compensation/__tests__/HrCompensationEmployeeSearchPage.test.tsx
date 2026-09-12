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
        name: 'Compensation Search Test Tenant',
    },
    workspaces: [],
    current: {
        type: 'TENANT' as const,
        organizational_assignment_id: null,
        organization_id: null,
        organization_unit_id: null,
        label: 'Compensation Search Test Tenant',
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
    HrCompensationEmployeeSearchPage,
} = await import(
    '@/modules/hr/compensation/HrCompensationEmployeeSearchPage'
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
                    <HrCompensationEmployeeSearchPage />
                </MemoryRouter>
            </QueryClientProvider>
        </ApiClientProvider>,
    );
}

const SAMPLE_EMPLOYEE_A = {
    employee_id: '01970000-0000-7000-8000-0000000001aa',
    membership_id: '01970000-0000-7000-8000-0000000001ab',
    person_id: '01970000-0000-7000-8000-0000000001ac',
    tenant_id: '01970000-0000-7000-8000-0000000000ff',
    nip: '198501012010011001',
    jabatan: 'GURU',
    nama: 'Siti Aminah',
    membership_status: 'ACTIVE',
    created_at: '2026-01-01T00:00:00+00:00',
};

const SAMPLE_EMPLOYEE_B = {
    employee_id: '01970000-0000-7000-8000-0000000002aa',
    membership_id: '01970000-0000-7000-8000-0000000002ab',
    person_id: '01970000-0000-7000-8000-0000000002ac',
    tenant_id: '01970000-0000-7000-8000-0000000000ff',
    nip: '198002022012011002',
    jabatan: 'STAFF',
    nama: 'Budi Santoso',
    membership_status: 'ACTIVE',
    created_at: '2026-01-02T00:00:00+00:00',
};

describe(
    'HrCompensationEmployeeSearchPage',
    () => {
        it(
            'renders employees returned by the tenant-wide employee directory endpoint',
            async () => {
                apiMockServer.use(
                    http.get(
                        '*/api/v1/hr/employees',
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
                                        SAMPLE_EMPLOYEE_A,
                                        SAMPLE_EMPLOYEE_B,
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
                        'Siti Aminah',
                    ),
                ).toBeInTheDocument();

                expect(
                    screen.getByText(
                        'Budi Santoso',
                    ),
                ).toBeInTheDocument();

                expect(
                    screen.getByText(
                        'Guru',
                    ),
                ).toBeInTheDocument();
            },
        );

        it(
            'filters employees on the currently loaded page by name or NIP',
            async () => {
                apiMockServer.use(
                    http.get(
                        '*/api/v1/hr/employees',
                        () =>
                            HttpResponse.json(
                                {
                                    status: 'success',
                                    data: [
                                        SAMPLE_EMPLOYEE_A,
                                        SAMPLE_EMPLOYEE_B,
                                    ],
                                    meta: {
                                        current_page: 1,
                                        last_page: 1,
                                        per_page: 15,
                                        total: 2,
                                    },
                                },
                            ),
                    ),
                );

                renderPage();

                await screen.findByText(
                    'Siti Aminah',
                );

                fireEvent.change(
                    screen.getByLabelText(
                        'Filter nama atau NIP pada halaman ini',
                    ),
                    {
                        target: {
                            value: 'budi',
                        },
                    },
                );

                await waitFor(
                    () => {
                        expect(
                            screen.queryByText(
                                'Siti Aminah',
                            ),
                        ).not.toBeInTheDocument();
                    },
                );

                expect(
                    screen.getByText(
                        'Budi Santoso',
                    ),
                ).toBeInTheDocument();
            },
        );

        it(
            'shows an error message when the directory request fails',
            async () => {
                apiMockServer.use(
                    http.get(
                        '*/api/v1/hr/employees',
                        () =>
                            HttpResponse.json(
                                {
                                    status: 'error',
                                    code: 'AUTHORIZATION_DENIED',
                                    message:
                                        'You are not allowed to perform this operation.',
                                },
                                {
                                    status: 403,
                                },
                            ),
                    ),
                );

                renderPage();

                expect(
                    await screen.findByRole(
                        'alert',
                    ),
                ).toHaveTextContent(
                    'Gagal memuat direktori pegawai',
                );
            },
        );
    },
);
