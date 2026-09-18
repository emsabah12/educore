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
        name: 'Placement Section Test Tenant',
    },
    workspaces: [],
    current: {
        type: 'ORGANIZATION' as const,
        organizational_assignment_id:
            '01970000-0000-7000-8000-0000000000cc',
        organization_id:
            '01970000-0000-7000-8000-0000000000dd',
        organization_unit_id: null,
        label: 'Placement Section Test Organization',
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
    EmploymentPlacementAndPositionSection,
} = await import(
    '@/modules/hr/workforce/EmploymentPlacementAndPositionSection'
);

const EMPLOYEE_ID =
    '01970000-0000-7000-8000-0000000070aa';

const EMPLOYMENT_ID =
    '01970000-0000-7000-8000-0000000071aa';

function renderSection() {
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
                <EmploymentPlacementAndPositionSection
                    employeeId={
                        EMPLOYEE_ID
                    }
                    employmentId={
                        EMPLOYMENT_ID
                    }
                />
            </QueryClientProvider>
        </ApiClientProvider>,
    );
}

describe(
    'EmploymentPlacementAndPositionSection',
    () => {
        it(
            'opens the panel and adds a new Placement',
            async () => {
                let latestPlacements: unknown[] = [];

                apiMockServer.use(
                    http.get(
                        `*/api/v1/hr/employments/${EMPLOYMENT_ID}/placements`,
                        () =>
                            HttpResponse.json(
                                {
                                    status: 'success',
                                    data: latestPlacements,
                                    meta: {
                                        current_page: 1,
                                        last_page: 1,
                                        per_page: 15,
                                        total: latestPlacements.length,
                                    },
                                },
                            ),
                    ),
                    http.get(
                        `*/api/v1/hr/employments/${EMPLOYMENT_ID}/position-assignments`,
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
                        '*/api/v1/hr/positions',
                        () =>
                            HttpResponse.json(
                                {
                                    status: 'success',
                                    data: [],
                                },
                            ),
                    ),
                    http.get(
                        `*/api/v1/hr/workspace/employees/${EMPLOYEE_ID}/organizational-assignments`,
                        () =>
                            HttpResponse.json(
                                {
                                    status: 'success',
                                    data: [
                                        {
                                            id: '01970000-0000-7000-8000-0000000072aa',
                                            organization_name: 'Sekolah Induk',
                                            organization_unit_name: null,
                                        },
                                    ],
                                },
                            ),
                    ),
                    http.post(
                        `*/api/v1/hr/workspace/employments/${EMPLOYMENT_ID}/placements`,
                        async ({
                            request,
                        }) => {
                            const body =
                                await request.json() as Record<string, unknown>;

                            expect(
                                body,
                            ).toEqual(
                                {
                                    organizational_assignment_id: '01970000-0000-7000-8000-0000000072aa',
                                    effective_from: '2026-01-01',
                                    is_primary: true,
                                },
                            );

                            latestPlacements = [
                                {
                                    id: '01970000-0000-7000-8000-0000000073aa',
                                    employment_id: EMPLOYMENT_ID,
                                    organizational_assignment_id: '01970000-0000-7000-8000-0000000072aa',
                                    effective_from: '2026-01-01',
                                    effective_to: null,
                                    is_primary: true,
                                },
                            ];

                            return HttpResponse.json(
                                {
                                    status: 'success',
                                    message: 'EmploymentPlacement created.',
                                    data: latestPlacements[0],
                                },
                                {
                                    status: 201,
                                },
                            );
                        },
                    ),
                );

                renderSection();

                fireEvent.click(
                    screen.getByRole(
                        'button',
                        {
                            name: 'Kelola Penempatan',
                        },
                    ),
                );

                await screen.findByText(
                    'Belum ada Placement.',
                );

                fireEvent.change(
                    screen.getByLabelText(
                        'Organizational Assignment',
                    ),
                    {
                        target: {
                            value: '01970000-0000-7000-8000-0000000072aa',
                        },
                    },
                );

                const [
                    effectiveFromInput,
                ] =
                    screen.getAllByLabelText(
                        'Berlaku Sejak',
                    );

                if (effectiveFromInput === undefined) {
                    throw new Error(
                        'Expected a "Berlaku Sejak" input to be present in the Placement form.',
                    );
                }

                fireEvent.change(
                    effectiveFromInput,
                    {
                        target: {
                            value: '2026-01-01',
                        },
                    },
                );

                fireEvent.click(
                    screen.getByRole(
                        'button',
                        {
                            name: 'Tambah Placement',
                        },
                    ),
                );

                await waitFor(
                    () => {
                        expect(
                            screen.getByText(
                                'Utama',
                            ),
                        ).toBeInTheDocument();
                    },
                );
            },
        );
    },
);
