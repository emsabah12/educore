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
        name: 'Compensation Adjustment Section Test Tenant',
    },
    workspaces: [],
    current: {
        type: 'TENANT' as const,
        organizational_assignment_id: null,
        organization_id: null,
        organization_unit_id: null,
        label: 'Compensation Adjustment Section Test Tenant',
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
    CompensationAdjustmentSection,
} = await import(
    '@/modules/hr/compensation/CompensationAdjustmentSection'
);

const EMPLOYMENT_ID =
    '01970000-0000-7000-8000-0000000050aa';

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
                <CompensationAdjustmentSection
                    employmentId={
                        EMPLOYMENT_ID
                    }
                />
            </QueryClientProvider>
        </ApiClientProvider>,
    );
}

describe(
    'CompensationAdjustmentSection',
    () => {
        it(
            'creates a DRAFT adjustment, submits it, then approves it',
            async () => {
                let currentAdjustment: Record<string, unknown> | null =
                    null;

                apiMockServer.use(
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
                        `*/api/v1/hr/employments/${EMPLOYMENT_ID}/compensation-adjustments`,
                        () =>
                            HttpResponse.json(
                                {
                                    status: 'success',
                                    data:
                                        currentAdjustment === null
                                            ? []
                                            : [
                                                currentAdjustment,
                                            ],
                                },
                            ),
                    ),
                    http.post(
                        `*/api/v1/hr/employments/${EMPLOYMENT_ID}/compensation-adjustments`,
                        async ({
                            request,
                        }) => {
                            const body =
                                await request.json() as Record<string, unknown>;

                            expect(
                                body,
                            ).toMatchObject(
                                {
                                    compensation_component_id: null,
                                    adjustment_type: 'ONE_TIME_EARNING',
                                    amount: 500000,
                                    currency_code: 'IDR',
                                    target_period_start: '2026-02-01',
                                    target_period_end: '2026-02-28',
                                    reason: 'Bonus proyek khusus',
                                },
                            );

                            currentAdjustment = {
                                id: '01970000-0000-7000-8000-0000000051aa',
                                employment_id: EMPLOYMENT_ID,
                                compensation_component_id: null,
                                adjustment_type: 'ONE_TIME_EARNING',
                                amount: '500000.0000',
                                currency_code: 'IDR',
                                target_period_start: '2026-02-01',
                                target_period_end: '2026-02-28',
                                status: 'DRAFT',
                                reason: 'Bonus proyek khusus',
                                requested_by_membership_id: READY_TENANT_WORKSPACE_STATE.context.membership.id,
                                approved_by_membership_id: null,
                                approved_at: null,
                                idempotency_key: body.idempotency_key,
                            };

                            return HttpResponse.json(
                                {
                                    status: 'success',
                                    data: currentAdjustment,
                                },
                                {
                                    status: 201,
                                },
                            );
                        },
                    ),
                    http.post(
                        `*/api/v1/hr/employments/${EMPLOYMENT_ID}/compensation-adjustments/:adjustmentId/submit`,
                        () => {
                            currentAdjustment = {
                                ...currentAdjustment,
                                status: 'SUBMITTED',
                            };

                            return HttpResponse.json(
                                {
                                    status: 'success',
                                    data: currentAdjustment,
                                },
                            );
                        },
                    ),
                    http.post(
                        `*/api/v1/hr/employments/${EMPLOYMENT_ID}/compensation-adjustments/:adjustmentId/approve`,
                        () => {
                            currentAdjustment = {
                                ...currentAdjustment,
                                status: 'APPROVED',

                                approved_by_membership_id: READY_TENANT_WORKSPACE_STATE.context.membership.id,
                            };

                            return HttpResponse.json(
                                {
                                    status: 'success',
                                    data: currentAdjustment,
                                },
                            );
                        },
                    ),
                );

                renderSection();

                await screen.findByText(
                    'Belum ada pengajuan penyesuaian kompensasi.',
                );

                fireEvent.change(
                    screen.getByLabelText(
                        'Nominal',
                    ),
                    {
                        target: {
                            value: '500000',
                        },
                    },
                );

                fireEvent.change(
                    screen.getByLabelText(
                        'Periode Target — Mulai',
                    ),
                    {
                        target: {
                            value: '2026-02-01',
                        },
                    },
                );

                fireEvent.change(
                    screen.getByLabelText(
                        'Periode Target — Selesai',
                    ),
                    {
                        target: {
                            value: '2026-02-28',
                        },
                    },
                );

                fireEvent.change(
                    screen.getByLabelText(
                        'Alasan',
                    ),
                    {
                        target: {
                            value: 'Bonus proyek khusus',
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
                                'Draf',
                            ),
                        ).toBeInTheDocument();
                    },
                );

                fireEvent.click(
                    screen.getByRole(
                        'button',
                        {
                            name: 'Ajukan',
                        },
                    ),
                );

                await waitFor(
                    () => {
                        expect(
                            screen.getByText(
                                'Diajukan',
                            ),
                        ).toBeInTheDocument();
                    },
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
