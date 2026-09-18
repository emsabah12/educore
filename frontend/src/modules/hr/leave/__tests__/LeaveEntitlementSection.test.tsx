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
        name: 'Leave Entitlement Section Test Tenant',
    },
    workspaces: [],
    current: {
        type: 'TENANT' as const,
        organizational_assignment_id: null,
        organization_id: null,
        organization_unit_id: null,
        label: 'Leave Entitlement Section Test Tenant',
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
    LeaveEntitlementSection,
} = await import(
    '@/modules/hr/leave/LeaveEntitlementSection'
);

const EMPLOYMENT_ID =
    '01970000-0000-7000-8000-00000000c0aa';

const LEAVE_TYPE = {
    id: '01970000-0000-7000-8000-00000000c1aa',
    tenant_id: '01970000-0000-7000-8000-0000000000ff',
    code: 'CUTI_TAHUNAN',
    name: 'Cuti Tahunan',
    category: 'LEAVE',
    balance_mode: 'BALANCE',
    unit: 'DAY',
    description: null,
    is_active: true,
    created_at: '2026-01-01T00:00:00Z',
    updated_at: '2026-01-01T00:00:00Z',
};

function changeById(
    id: string,
    value: string,
) {
    const element =
        document.getElementById(id);

    if (element === null) {
        throw new Error(
            `Expected an element with id="${id}" to be present.`,
        );
    }

    fireEvent.change(
        element,
        {
            target: {
                value,
            },
        },
    );
}

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
                <LeaveEntitlementSection
                    employmentId={
                        EMPLOYMENT_ID
                    }
                />
            </QueryClientProvider>
        </ApiClientProvider>,
    );
}

describe(
    'LeaveEntitlementSection',
    () => {
        it(
            'generates a new Entitlement, then adjusts its balance',
            async () => {
                let currentEntitlement: Record<string, unknown> | null = null;

                apiMockServer.use(
                    http.get(
                        '*/api/v1/hr/leave-types',
                        () =>
                            HttpResponse.json(
                                {
                                    status: 'success',
                                    data: [
                                        LEAVE_TYPE,
                                    ],
                                },
                            ),
                    ),
                    http.get(
                        `*/api/v1/hr/employments/${EMPLOYMENT_ID}/leave-entitlements`,
                        () =>
                            HttpResponse.json(
                                {
                                    status: 'success',
                                    data:
                                        currentEntitlement === null
                                            ? []
                                            : [
                                                currentEntitlement,
                                            ],
                                },
                            ),
                    ),
                    http.post(
                        `*/api/v1/hr/employments/${EMPLOYMENT_ID}/leave-entitlements/generate`,
                        async ({
                            request,
                        }) => {
                            const body =
                                await request.json() as Record<string, unknown>;

                            expect(
                                body,
                            ).toEqual(
                                {
                                    leave_type_id: LEAVE_TYPE.id,
                                    period_start: '2026-01-01',
                                    period_end: '2026-12-31',
                                },
                            );

                            currentEntitlement = {
                                id: '01970000-0000-7000-8000-00000000c2aa',
                                tenant_id: '01970000-0000-7000-8000-0000000000ff',
                                employment_id: EMPLOYMENT_ID,
                                leave_type_id: LEAVE_TYPE.id,
                                entitlement_policy_id: null,
                                period_start: '2026-01-01',
                                period_end: '2026-12-31',
                                status: 'ACTIVE',
                                created_at: '2026-01-15T00:00:00Z',
                                updated_at: '2026-01-15T00:00:00Z',
                            };

                            return HttpResponse.json(
                                {
                                    status: 'success',
                                    data: currentEntitlement,
                                },
                                {
                                    status: 201,
                                },
                            );
                        },
                    ),
                    http.post(
                        '*/api/v1/hr/leave-entitlements/:id/adjustments',
                        async ({
                            request,
                        }) => {
                            const body =
                                await request.json() as Record<string, unknown>;

                            expect(
                                body.units_delta,
                            ).toBe(
                                5,
                            );

                            return HttpResponse.json(
                                {
                                    status: 'success',
                                    data: {
                                        entitlement_id: '01970000-0000-7000-8000-00000000c2aa',
                                        balance: '17.00',
                                    },
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
                    'Belum ada Entitlement untuk Employment ini.',
                );

                changeById(
                    'entitlement-generate-leave-type',
                    LEAVE_TYPE.id,
                );

                changeById(
                    'entitlement-generate-period-start',
                    '2026-01-01',
                );

                changeById(
                    'entitlement-generate-period-end',
                    '2026-12-31',
                );

                fireEvent.click(
                    screen.getByRole(
                        'button',
                        {
                            name: 'Generate',
                        },
                    ),
                );

                await screen.findByText(
                    'Sesuaikan Saldo',
                );

                fireEvent.click(
                    screen.getByRole(
                        'button',
                        {
                            name: 'Sesuaikan Saldo',
                        },
                    ),
                );

                changeById(
                    'adjust-units-01970000-0000-7000-8000-00000000c2aa',
                    '5',
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
                                'Saldo sekarang: 17.00',
                            ),
                        ).toBeInTheDocument();
                    },
                );
            },
        );
    },
);
