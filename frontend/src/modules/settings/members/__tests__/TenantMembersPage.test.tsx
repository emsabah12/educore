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
        name: 'Tenant Members Test Tenant',
    },
    workspaces: [],
    current: {
        type: 'TENANT' as const,
        organizational_assignment_id: null,
        organization_id: null,
        organization_unit_id: null,
        label: 'Tenant Members Test Tenant',
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
    TenantMembersPage,
} = await import(
    '@/modules/settings/members/TenantMembersPage'
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
                    <TenantMembersPage />
                </MemoryRouter>
            </QueryClientProvider>
        </ApiClientProvider>,
    );
}

describe(
    'TenantMembersPage',
    () => {
        it(
            'renders tenant members with their current roles',
            async () => {
                apiMockServer.use(
                    http.get(
                        '*/api/v1/user/tenant-memberships',
                        () =>
                            HttpResponse.json(
                                {
                                    status: 'success',
                                    data: [
                                        {
                                            membership_id: '01970000-0000-7000-8000-0000000020aa',
                                            person_name: 'Budi Santoso',
                                            email: 'budi@educore.test',
                                            roles: [
                                                {
                                                    id: '01970000-0000-7000-8000-0000000021aa',
                                                    name: 'hr-officer',
                                                    display_name: 'HR Officer',
                                                },
                                            ],
                                        },
                                    ],
                                },
                            ),
                    ),
                    http.get(
                        '*/api/v1/core/authorization/roles',
                        () =>
                            HttpResponse.json(
                                {
                                    status: 'success',
                                    data: [],
                                },
                            ),
                    ),
                    http.get(
                        '*/api/v1/core/tenant-roles',
                        () =>
                            HttpResponse.json(
                                {
                                    status: 'success',
                                    data: [],
                                },
                            ),
                    ),
                );

                renderPage();

                expect(
                    await screen.findByText(
                        'Budi Santoso',
                    ),
                ).toBeInTheDocument();

                expect(
                    screen.getByText(
                        'budi@educore.test',
                    ),
                ).toBeInTheDocument();

                expect(
                    screen.getByText(
                        'HR Officer',
                    ),
                ).toBeInTheDocument();
            },
        );

        it(
            'assigns a global role to a member and hides it from the picker once assigned',
            async () => {
                let latestRoles: unknown[] = [];

                apiMockServer.use(
                    http.get(
                        '*/api/v1/user/tenant-memberships',
                        () =>
                            HttpResponse.json(
                                {
                                    status: 'success',
                                    data: [
                                        {
                                            membership_id: '01970000-0000-7000-8000-0000000020aa',
                                            person_name: 'Dewi Lestari',
                                            email: 'dewi@educore.test',
                                            roles: latestRoles,
                                        },
                                    ],
                                },
                            ),
                    ),
                    http.get(
                        '*/api/v1/core/authorization/roles',
                        () =>
                            HttpResponse.json(
                                {
                                    status: 'success',
                                    data: [
                                        {
                                            id: '01970000-0000-7000-8000-0000000022aa',
                                            name: 'hr-officer',
                                            display_name: 'HR Officer',
                                            description: null,
                                        },
                                    ],
                                },
                            ),
                    ),
                    http.get(
                        '*/api/v1/core/tenant-roles',
                        () =>
                            HttpResponse.json(
                                {
                                    status: 'success',
                                    data: [],
                                },
                            ),
                    ),
                    http.post(
                        '*/api/v1/user/memberships/:membershipId/assign-role',
                        async ({
                            request,
                        }) => {
                            const body =
                                await request.json() as Record<string, unknown>;

                            expect(
                                body,
                            ).toEqual(
                                {
                                    role_id: '01970000-0000-7000-8000-0000000022aa',
                                },
                            );

                            latestRoles = [
                                {
                                    id: '01970000-0000-7000-8000-0000000022aa',
                                    name: 'hr-officer',
                                    display_name: 'HR Officer',
                                },
                            ];

                            return HttpResponse.json(
                                {
                                    status: 'success',
                                    message: 'Role berhasil ditetapkan pada target membership.',
                                    data: {
                                        membership_id: '01970000-0000-7000-8000-0000000020aa',
                                        role_id: '01970000-0000-7000-8000-0000000022aa',
                                    },
                                },
                            );
                        },
                    ),
                );

                renderPage();

                await screen.findByText(
                    'Dewi Lestari',
                );

                fireEvent.change(
                    screen.getByLabelText(
                        'Pilih role untuk Dewi Lestari',
                    ),
                    {
                        target: {
                            value: '01970000-0000-7000-8000-0000000022aa',
                        },
                    },
                );

                fireEvent.click(
                    screen.getByRole(
                        'button',
                        {
                            name: 'Tetapkan',
                        },
                    ),
                );

                await waitFor(
                    () => {
                        expect(
                            screen.getByText(
                                'Semua role sudah ditetapkan',
                            ),
                        ).toBeInTheDocument();
                    },
                );
            },
        );
    },
);
