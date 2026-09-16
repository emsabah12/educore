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
        name: 'Compensation Assignment Section Test Tenant',
    },
    workspaces: [],
    current: {
        type: 'TENANT' as const,
        organizational_assignment_id: null,
        organization_id: null,
        organization_unit_id: null,
        label: 'Compensation Assignment Section Test Tenant',
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
    CompensationAssignmentSection,
} = await import(
    '@/modules/hr/compensation/CompensationAssignmentSection'
);

const EMPLOYMENT_ID =
    '01970000-0000-7000-8000-0000000030aa';

const COMPONENT_FIXED = {
    id: '01970000-0000-7000-8000-0000000031aa',
    tenant_id: '01970000-0000-7000-8000-0000000000ff',
    code: 'BASE_SALARY',
    name: 'Gaji Pokok',
    category: 'BASE_PAY',
    value_mode: 'FIXED_AMOUNT',
    unit_code: null,
    periodicity: 'MONTHLY',
    description: null,
    is_active: true,
    created_at: '2026-01-01T00:00:00+00:00',
    updated_at: '2026-01-01T00:00:00+00:00',
};

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
                <CompensationAssignmentSection
                    employmentId={
                        EMPLOYMENT_ID
                    }
                />
            </QueryClientProvider>
        </ApiClientProvider>,
    );
}

describe(
    'CompensationAssignmentSection',
    () => {
        it(
            'creates a new DRAFT assignment from the inline form',
            async () => {
                let latestAssignments: unknown[] = [];

                apiMockServer.use(
                    http.get(
                        `*/api/v1/hr/employments/${EMPLOYMENT_ID}/compensation-assignments`,
                        () =>
                            HttpResponse.json(
                                {
                                    status: 'success',
                                    data: latestAssignments,
                                },
                            ),
                    ),
                    http.get(
                        '*/api/v1/hr/compensation/components',
                        () =>
                            HttpResponse.json(
                                {
                                    status: 'success',
                                    data: [
                                        COMPONENT_FIXED,
                                    ],
                                },
                            ),
                    ),
                    http.post(
                        `*/api/v1/hr/employments/${EMPLOYMENT_ID}/compensation-assignments`,
                        async ({
                            request,
                        }) => {
                            const body =
                                await request.json() as Record<string, unknown>;

                            expect(
                                body,
                            ).toEqual(
                                {
                                    compensation_component_id:
                                        COMPONENT_FIXED.id,

                                    employment_position_assignment_id: null,
                                    amount: 5000000,
                                    rate: null,
                                    currency_code: 'IDR',
                                    effective_from: '2026-01-01',
                                    effective_to: null,
                                    reason: null,
                                },
                            );

                            const created = {
                                id: '01970000-0000-7000-8000-0000000032aa',
                                employment_id: EMPLOYMENT_ID,
                                compensation_component_id: COMPONENT_FIXED.id,
                                employment_position_assignment_id: null,
                                status: 'DRAFT',
                                amount: '5000000.0000',
                                rate: null,
                                currency_code: 'IDR',
                                effective_from: '2026-01-01',
                                effective_to: null,
                                supersedes_assignment_id: null,
                                approved_by_membership_id: null,
                                approved_at: null,
                                ended_at: null,
                                reason: null,
                            };

                            latestAssignments = [
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

                renderSection();

                await screen.findByText(
                    'Belum ada riwayat gaji/tunjangan.',
                );

                fireEvent.change(
                    screen.getByLabelText(
                        'Komponen',
                    ),
                    {
                        target: {
                            value: COMPONENT_FIXED.id,
                        },
                    },
                );

                fireEvent.change(
                    screen.getByLabelText(
                        'Nominal',
                    ),
                    {
                        target: {
                            value: '5000000',
                        },
                    },
                );

                fireEvent.change(
                    screen.getByLabelText(
                        'Berlaku Sejak',
                    ),
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
                            name: 'Buat Draf',
                        },
                    ),
                );

                await waitFor(
                    () => {
                        expect(
                            screen.getByText(
                                'Gaji Pokok',
                            ),
                        ).toBeInTheDocument();
                    },
                );

                expect(
                    screen.getByText(
                        'Draf',
                    ),
                ).toBeInTheDocument();
            },
        );

        it(
            'approves a DRAFT assignment',
            async () => {
                let currentStatus = 'DRAFT';

                const baseAssignment = {
                    id: '01970000-0000-7000-8000-0000000033aa',
                    employment_id: EMPLOYMENT_ID,
                    compensation_component_id: COMPONENT_FIXED.id,
                    employment_position_assignment_id: null,
                    amount: '5000000.0000',
                    rate: null,
                    currency_code: 'IDR',
                    effective_from: '2026-01-01',
                    effective_to: null,
                    supersedes_assignment_id: null,
                    approved_by_membership_id: null,
                    approved_at: null,
                    ended_at: null,
                    reason: null,
                };

                apiMockServer.use(
                    http.get(
                        `*/api/v1/hr/employments/${EMPLOYMENT_ID}/compensation-assignments`,
                        () =>
                            HttpResponse.json(
                                {
                                    status: 'success',
                                    data: [
                                        {
                                            ...baseAssignment,
                                            status: currentStatus,
                                        },
                                    ],
                                },
                            ),
                    ),
                    http.get(
                        '*/api/v1/hr/compensation/components',
                        () =>
                            HttpResponse.json(
                                {
                                    status: 'success',
                                    data: [
                                        COMPONENT_FIXED,
                                    ],
                                },
                            ),
                    ),
                    http.post(
                        `*/api/v1/hr/employments/${EMPLOYMENT_ID}/compensation-assignments/${baseAssignment.id}/approve`,
                        () => {
                            currentStatus = 'APPROVED';

                            return HttpResponse.json(
                                {
                                    status: 'success',
                                    data: {
                                        ...baseAssignment,
                                        status: 'APPROVED',
                                        approved_by_membership_id: READY_TENANT_WORKSPACE_STATE.context.membership.id,
                                    },
                                },
                            );
                        },
                    ),
                );

                renderSection();

                await screen.findByText(
                    'Draf',
                );

                fireEvent.click(
                    screen.getByRole(
                        'button',
                        {
                            name: 'Setujui',
                        },
                    ),
                );

                await waitFor(
                    () => {
                        expect(
                            screen.getByText(
                                'Disetujui',
                            ),
                        ).toBeInTheDocument();
                    },
                );
            },
        );
    },
);
