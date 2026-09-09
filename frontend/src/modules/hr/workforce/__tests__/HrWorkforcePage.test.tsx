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
        name: 'Workforce Test Tenant',
    },
    workspaces: [],
    current: {
        type: 'ORGANIZATION' as const,
        organizational_assignment_id:
            '01970000-0000-7000-8000-0000000000cc',
        organization_id:
            '01970000-0000-7000-8000-0000000000dd',
        organization_unit_id: null,
        label: 'Workforce Test Organization',
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

/*
 * Imported AFTER the mock above so the module under test
 * resolves the mocked workspace hook rather than the real
 * Provider-backed implementation.
 */
const {
    HrWorkforcePage,
} = await import(
    '@/modules/hr/workforce/HrWorkforcePage'
);

function renderWorkforcePage() {
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
                    <HrWorkforcePage />
                </MemoryRouter>
            </QueryClientProvider>
        </ApiClientProvider>,
    );
}

describe(
    'HrWorkforcePage',
    () => {
        it(
            'renders employees returned by the workspace listing endpoint',
            async () => {
                apiMockServer.use(
                    http.get(
                        '*/api/v1/hr/workspace/employees',
                        () =>
                            HttpResponse.json(
                                {
                                    status: 'success',
                                    data: [
                                        {
                                            id: '01970000-0000-7000-8000-0000000000ee',
                                            tenant_id:
                                                '01970000-0000-7000-8000-0000000000bb',
                                            membership_id:
                                                '01970000-0000-7000-8000-0000000000ff',
                                            nip: 'NIP-001',
                                            jabatan: 'GURU',
                                            nama: 'Budi Santoso',
                                            created_at:
                                                '2026-01-01T00:00:00Z',
                                        },
                                    ],
                                    meta: {
                                        current_page: 1,
                                        last_page: 1,
                                        per_page: 15,
                                        total: 1,
                                    },
                                },
                            ),
                    ),
                );

                renderWorkforcePage();

                expect(
                    await screen.findByText(
                        'Budi Santoso',
                    ),
                ).toBeInTheDocument();

                expect(
                    screen.getByText(
                        'NIP-001',
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
            'shows an empty state when the workspace has no visible employees',
            async () => {
                apiMockServer.use(
                    http.get(
                        '*/api/v1/hr/workspace/employees',
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

                renderWorkforcePage();

                expect(
                    await screen.findByText(
                        'Belum ada pegawai yang terlihat di workspace ini.',
                    ),
                ).toBeInTheDocument();
            },
        );

        it(
            'shows an error state when the request fails',
            async () => {
                apiMockServer.use(
                    http.get(
                        '*/api/v1/hr/workspace/employees',
                        () =>
                            HttpResponse.json(
                                {
                                    status: 'error',
                                    code: 'AUTHORIZATION_DENIED',
                                    message: 'Denied.',
                                },
                                {
                                    status: 403,
                                },
                            ),
                    ),
                );

                renderWorkforcePage();

                expect(
                    await screen.findByRole(
                        'alert',
                    ),
                ).toHaveTextContent(
                    'Gagal memuat daftar pegawai',
                );
            },
        );

        it(
            'requests the clicked page from the numbered pagination control',
            async () => {
                const requestedPages: string[] = [];

                apiMockServer.use(
                    http.get(
                        '*/api/v1/hr/workspace/employees',
                        (
                            {
                                request,
                            },
                        ) => {
                            const url =
                                new URL(
                                    request.url,
                                );

                            const page =
                                url.searchParams.get(
                                    'page',
                                )
                                ?? '1';

                            requestedPages.push(
                                page,
                            );

                            return HttpResponse.json(
                                {
                                    status: 'success',
                                    data: [
                                        {
                                            id: '01970000-0000-7000-8000-0000000000ee',
                                            tenant_id:
                                                '01970000-0000-7000-8000-0000000000bb',
                                            membership_id:
                                                '01970000-0000-7000-8000-0000000000ff',
                                            nip: 'NIP-001',
                                            jabatan: 'GURU',
                                            nama: 'Budi Santoso',
                                            created_at:
                                                '2026-01-01T00:00:00Z',
                                        },
                                    ],
                                    meta: {
                                        current_page: Number(
                                            page,
                                        ),
                                        last_page: 5,
                                        per_page: 15,
                                        total: 75,
                                    },
                                },
                            );
                        },
                    ),
                );

                renderWorkforcePage();

                await screen.findByText(
                    'Budi Santoso',
                );

                expect(
                    screen.getByRole(
                        'button',
                        {
                            name: 'Halaman 1',
                        },
                    ),
                ).toHaveAttribute(
                    'aria-current',
                    'page',
                );

                fireEvent.click(
                    screen.getByRole(
                        'button',
                        {
                            name: 'Halaman 3',
                        },
                    ),
                );

                await waitFor(
                    () => {
                        expect(
                            screen.getByRole(
                                'button',
                                {
                                    name: 'Halaman 3',
                                },
                            ),
                        ).toHaveAttribute(
                            'aria-current',
                            'page',
                        );
                    },
                );

                expect(
                    requestedPages,
                ).toContain(
                    '3',
                );
            },
        );
    },
);