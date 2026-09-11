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
        name: 'Organization Members Test Tenant',
    },
    workspaces: [],
    current: {
        type: 'TENANT' as const,
        organizational_assignment_id: null,
        organization_id: null,
        organization_unit_id: null,
        label: 'Organization Members Test Tenant',
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
    OrganizationMembersPage,
} = await import(
    '@/modules/settings/organizations/OrganizationMembersPage'
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

const SAMPLE_ORG_LEVEL_ASSIGNMENT = {
    id: '01970000-0000-7000-8000-0000000000ii',
    membership_id: '01970000-0000-7000-8000-0000000000jj',
    membership_name: 'Budi Santoso',
    organization_id: ORGANIZATION_ID,
    organization_unit_id: null,
    organization_unit_name: null,
    status: 'ACTIVE',
    created_at: '2026-09-03T08:00:00+00:00',
};

const SAMPLE_UNIT_LEVEL_ASSIGNMENT = {
    id: '01970000-0000-7000-8000-0000000000kk',
    membership_id: '01970000-0000-7000-8000-0000000000ll',
    membership_name: 'Siti Aminah',
    organization_id: ORGANIZATION_ID,
    organization_unit_id: SAMPLE_UNIT.id,
    organization_unit_name: SAMPLE_UNIT.name,
    status: 'ACTIVE',
    created_at: '2026-09-04T08:00:00+00:00',
};

const SAMPLE_CANDIDATE = {
    membership_id: '01970000-0000-7000-8000-0000000000mm',
    name: 'Amir Hamzah',
};

function renderOrganizationMembersPage() {
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
                        [`/settings/organizations/${ORGANIZATION_ID}/members`]
                    }
                >
                    <Routes>
                        <Route
                            path="/settings/organizations/:organizationId/members"
                            element={
                                <OrganizationMembersPage />
                            }
                        />
                    </Routes>
                </MemoryRouter>
            </QueryClientProvider>
        </ApiClientProvider>,
    );
}

function mockBaseline(
    {
        units = [SAMPLE_UNIT],
        assignments = [],
    }: {
        units?:
            readonly (typeof SAMPLE_UNIT)[];
        assignments?:
            readonly unknown[];
    } = {},
) {
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
        http.get(
            `*/api/v1/core/organizations/${ORGANIZATION_ID}/units`,
            () =>
                HttpResponse.json(
                    {
                        status: 'success',
                        data: units,
                    },
                ),
        ),
        http.get(
            `*/api/v1/core/organizations/${ORGANIZATION_ID}/assignments`,
            () =>
                HttpResponse.json(
                    {
                        status: 'success',
                        data: assignments,
                    },
                ),
        ),
    );
}

describe(
    'OrganizationMembersPage',
    () => {
        it(
            'renders the organization name and existing assignments',
            async () => {
                mockBaseline(
                    {
                        assignments: [
                            SAMPLE_ORG_LEVEL_ASSIGNMENT,
                            SAMPLE_UNIT_LEVEL_ASSIGNMENT,
                        ],
                    },
                );

                renderOrganizationMembersPage();

                expect(
                    await screen.findByRole(
                        'heading',
                        {
                            name: 'Anggota — Kampus Utama',
                        },
                    ),
                ).toBeInTheDocument();

                expect(
                    screen.getByText(
                        'Budi Santoso',
                    ),
                ).toBeInTheDocument();

                expect(
                    screen.getByText(
                        'Level Organisasi',
                    ),
                ).toBeInTheDocument();

                expect(
                    screen.getByText(
                        'Siti Aminah',
                    ),
                ).toBeInTheDocument();

                expect(
                    screen.getByRole(
                        'cell',
                        {
                            name: 'Fakultas Teknik',
                        },
                    ),
                ).toBeInTheDocument();
            },
        );

        it(
            'shows an empty state when there are no assignments yet',
            async () => {
                mockBaseline();

                renderOrganizationMembersPage();

                expect(
                    await screen.findByText(
                        'Belum ada anggota yang ditempatkan.',
                    ),
                ).toBeInTheDocument();
            },
        );

        it(
            'searches candidate memberships and lets the user pick one',
            async () => {
                mockBaseline();

                apiMockServer.use(
                    http.get(
                        `*/api/v1/core/organizations/${ORGANIZATION_ID}/assignments/candidate-memberships`,
                        () =>
                            HttpResponse.json(
                                {
                                    status: 'success',
                                    data: [SAMPLE_CANDIDATE],
                                },
                            ),
                    ),
                );

                renderOrganizationMembersPage();

                await screen.findByText(
                    'Belum ada anggota yang ditempatkan.',
                );

                fireEvent.change(
                    screen.getByLabelText(
                        'Orang',
                    ),
                    {
                        target: {
                            value: 'amir',
                        },
                    },
                );

                const candidateButton =
                    await screen.findByRole(
                        'button',
                        {
                            name: SAMPLE_CANDIDATE.name,
                        },
                        {
                            timeout: 2000,
                        },
                    );

                fireEvent.click(
                    candidateButton,
                );

                expect(
                    await screen.findByText(
                        SAMPLE_CANDIDATE.name,
                    ),
                ).toBeInTheDocument();

                expect(
                    screen.getByRole(
                        'button',
                        {
                            name: 'Ganti',
                        },
                    ),
                ).toBeInTheDocument();
            },
        );

        it(
            'assigns the selected candidate directly to the organization by default',
            async () => {
                mockBaseline();

                apiMockServer.use(
                    http.get(
                        `*/api/v1/core/organizations/${ORGANIZATION_ID}/assignments/candidate-memberships`,
                        () =>
                            HttpResponse.json(
                                {
                                    status: 'success',
                                    data: [SAMPLE_CANDIDATE],
                                },
                            ),
                    ),
                    http.post(
                        `*/api/v1/core/organizations/${ORGANIZATION_ID}/assignments`,
                        async (
                            {
                                request,
                            },
                        ) => {
                            const body =
                                await request.json() as {
                                    membership_id:
                                        string;
                                    organization_unit_id:
                                        string | null;
                                };

                            expect(
                                body.membership_id,
                            ).toBe(
                                SAMPLE_CANDIDATE.membership_id,
                            );

                            expect(
                                body.organization_unit_id,
                            ).toBeNull();

                            return HttpResponse.json(
                                {
                                    status: 'success',
                                    data: {
                                        ...SAMPLE_ORG_LEVEL_ASSIGNMENT,
                                        membership_id:
                                            SAMPLE_CANDIDATE.membership_id,
                                        membership_name:
                                            SAMPLE_CANDIDATE.name,
                                    },
                                },
                                {
                                    status: 201,
                                },
                            );
                        },
                    ),
                );

                renderOrganizationMembersPage();

                await screen.findByText(
                    'Belum ada anggota yang ditempatkan.',
                );

                fireEvent.change(
                    screen.getByLabelText(
                        'Orang',
                    ),
                    {
                        target: {
                            value: 'amir',
                        },
                    },
                );

                fireEvent.click(
                    await screen.findByRole(
                        'button',
                        {
                            name: SAMPLE_CANDIDATE.name,
                        },
                        {
                            timeout: 2000,
                        },
                    ),
                );

                fireEvent.click(
                    screen.getByRole(
                        'button',
                        {
                            name: 'Tempatkan',
                        },
                    ),
                );

                /*
                 * On success the form resets selectedCandidate to
                 * null, which correctly makes "Tempatkan" disabled
                 * AGAIN (nothing is selected anymore) — a vanished
                 * "Ganti" button is therefore the right signal that
                 * submission succeeded and the picker returned to
                 * its search state, not an un-disabled submit button.
                 */
                await waitFor(
                    () => {
                        expect(
                            screen.queryByRole(
                                'button',
                                {
                                    name: 'Ganti',
                                },
                            ),
                        ).not.toBeInTheDocument();
                    },
                );

                expect(
                    screen.getByLabelText(
                        'Orang',
                    ),
                ).toHaveValue(
                    '',
                );
            },
        );

        it(
            'deactivates an active assignment',
            async () => {
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

                /*
                 * Stateful mock — the GET handler reflects whatever
                 * the deactivate POST most recently did, so the UI
                 * assertion below actually exercises the
                 * invalidate-then-refetch flow instead of asserting
                 * against a static fixture that could never change.
                 */
                let currentStatus:
                    'ACTIVE' | 'INACTIVE' =
                    'ACTIVE';

                apiMockServer.use(
                    http.get(
                        `*/api/v1/core/organizations/${ORGANIZATION_ID}/assignments`,
                        () =>
                            HttpResponse.json(
                                {
                                    status: 'success',
                                    data: [
                                        {
                                            ...SAMPLE_ORG_LEVEL_ASSIGNMENT,
                                            status: currentStatus,
                                        },
                                    ],
                                },
                            ),
                    ),
                    http.post(
                        `*/api/v1/core/organizations/${ORGANIZATION_ID}/assignments/${SAMPLE_ORG_LEVEL_ASSIGNMENT.id}/deactivate`,
                        () => {
                            currentStatus =
                                'INACTIVE';

                            return HttpResponse.json(
                                {
                                    status: 'success',
                                    data: {
                                        ...SAMPLE_ORG_LEVEL_ASSIGNMENT,
                                        status: currentStatus,
                                    },
                                },
                            );
                        },
                    ),
                );

                renderOrganizationMembersPage();

                const deactivateButton =
                    await screen.findByRole(
                        'button',
                        {
                            name: 'Nonaktifkan',
                        },
                    );

                fireEvent.click(
                    deactivateButton,
                );

                await waitFor(
                    () => {
                        expect(
                            screen.getByRole(
                                'cell',
                                {
                                    name: 'Nonaktif',
                                },
                            ),
                        ).toBeInTheDocument();
                    },
                );

                expect(
                    screen.queryByRole(
                        'button',
                        {
                            name: 'Nonaktifkan',
                        },
                    ),
                ).not.toBeInTheDocument();
            },
        );

        it(
            'shows a not-found message when the organization does not exist',
            async () => {
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
                    http.get(
                        `*/api/v1/core/organizations/${ORGANIZATION_ID}/assignments`,
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

                renderOrganizationMembersPage();

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
                mockBaseline();

                renderOrganizationMembersPage();

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
