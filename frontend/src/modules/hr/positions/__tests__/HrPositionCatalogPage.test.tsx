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
            id: '01970000-0000-7000-8000-0000000000ee',
            status: 'ACTIVE' as const,
        },
        tenant: {
            id: '01970000-0000-7000-8000-0000000000ff',
        },
    },
    tenant: {
        name: 'Position Catalog Page Test Tenant',
    },
    workspaces: [],
    current: {
        type: 'TENANT' as const,
        organizational_assignment_id: null,
        organization_id: null,
        organization_unit_id: null,
        label: 'Position Catalog Page Test Tenant',
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
    HrPositionCatalogPage,
} = await import(
    '@/modules/hr/positions/HrPositionCatalogPage'
);

function renderCatalogPage() {
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
                <HrPositionCatalogPage />
            </QueryClientProvider>
        </ApiClientProvider>,
    );
}

describe(
    'HrPositionCatalogPage',
    () => {
        it(
            'renders existing positions and creates a new one from the inline form',
            async () => {
                let latestPositions: unknown[] = [
                    {
                        id: '01970000-0000-7000-8000-0000000080aa',
                        tenant_id: '01970000-0000-7000-8000-0000000000ff',
                        code: 'GURU_MTK',
                        name: 'Guru Matematika',
                        description: null,
                        is_active: true,
                        created_at: '2026-01-01T00:00:00Z',
                        updated_at: '2026-01-01T00:00:00Z',
                    },
                ];

                apiMockServer.use(
                    http.get(
                        '*/api/v1/hr/positions',
                        () =>
                            HttpResponse.json(
                                {
                                    status: 'success',
                                    data: latestPositions,
                                },
                            ),
                    ),
                    http.post(
                        '*/api/v1/hr/positions',
                        async ({
                            request,
                        }) => {
                            const body =
                                await request.json() as Record<string, unknown>;

                            expect(
                                body,
                            ).toEqual(
                                {
                                    code: 'KEPALA_TU',
                                    name: 'Kepala Tata Usaha',
                                    description: null,
                                },
                            );

                            const created = {
                                id: '01970000-0000-7000-8000-0000000081aa',
                                tenant_id: '01970000-0000-7000-8000-0000000000ff',
                                code: 'KEPALA_TU',
                                name: 'Kepala Tata Usaha',
                                description: null,
                                is_active: true,
                                created_at: '2026-01-02T00:00:00Z',
                                updated_at: '2026-01-02T00:00:00Z',
                            };

                            latestPositions = [
                                ...latestPositions,
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

                renderCatalogPage();

                await screen.findByText(
                    'Guru Matematika',
                );

                fireEvent.change(
                    screen.getByLabelText(
                        'Kode',
                    ),
                    {
                        target: {
                            value: 'KEPALA_TU',
                        },
                    },
                );

                fireEvent.change(
                    screen.getByLabelText(
                        'Nama',
                    ),
                    {
                        target: {
                            value: 'Kepala Tata Usaha',
                        },
                    },
                );

                fireEvent.click(
                    screen.getByRole(
                        'button',
                        {
                            name: 'Simpan',
                        },
                    ),
                );

                await waitFor(
                    () => {
                        expect(
                            screen.getByText(
                                'Kepala Tata Usaha',
                            ),
                        ).toBeInTheDocument();
                    },
                );
            },
        );

        it(
            'shows a friendly message when the code is already taken',
            async () => {
                apiMockServer.use(
                    http.get(
                        '*/api/v1/hr/positions',
                        () =>
                            HttpResponse.json(
                                {
                                    status: 'success',
                                    data: [],
                                },
                            ),
                    ),
                    http.post(
                        '*/api/v1/hr/positions',
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

                renderCatalogPage();

                await screen.findByText(
                    'Belum ada jabatan terdaftar.',
                );

                fireEvent.change(
                    screen.getByLabelText(
                        'Kode',
                    ),
                    {
                        target: {
                            value: 'GURU_MTK',
                        },
                    },
                );

                fireEvent.change(
                    screen.getByLabelText(
                        'Nama',
                    ),
                    {
                        target: {
                            value: 'Guru Matematika',
                        },
                    },
                );

                fireEvent.click(
                    screen.getByRole(
                        'button',
                        {
                            name: 'Simpan',
                        },
                    ),
                );

                expect(
                    await screen.findByRole(
                        'alert',
                    ),
                ).toHaveTextContent(
                    'Kode ini sudah dipakai jabatan lain.',
                );
            },
        );
    },
);
