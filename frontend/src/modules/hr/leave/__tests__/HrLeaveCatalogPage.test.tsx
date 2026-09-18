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
        name: 'Leave Catalog Page Test Tenant',
    },
    workspaces: [],
    current: {
        type: 'TENANT' as const,
        organizational_assignment_id: null,
        organization_id: null,
        organization_unit_id: null,
        label: 'Leave Catalog Page Test Tenant',
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
    HrLeaveCatalogPage,
} = await import(
    '@/modules/hr/leave/HrLeaveCatalogPage'
);

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

const LEAVE_TYPE = {
    id: '01970000-0000-7000-8000-0000000090aa',
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

function mockBaseEndpoints(
    overrides: {
        leaveTypes?: unknown[];
        entitlementPolicies?: unknown[];
        approvalPolicies?: unknown[];
    } = {},
) {
    apiMockServer.use(
        http.get(
            '*/api/v1/hr/leave-types',
            () =>
                HttpResponse.json(
                    {
                        status: 'success',
                        data:
                            overrides.leaveTypes
                            ?? [
                                LEAVE_TYPE,
                            ],
                    },
                ),
        ),
        http.get(
            '*/api/v1/hr/leave-entitlement-policies',
            () =>
                HttpResponse.json(
                    {
                        status: 'success',
                        data:
                            overrides.entitlementPolicies
                            ?? [],
                    },
                ),
        ),
        http.get(
            '*/api/v1/hr/leave-approval-policies',
            () =>
                HttpResponse.json(
                    {
                        status: 'success',
                        data:
                            overrides.approvalPolicies
                            ?? [],
                    },
                ),
        ),
    );
}

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
                <HrLeaveCatalogPage />
            </QueryClientProvider>
        </ApiClientProvider>,
    );
}

describe(
    'HrLeaveCatalogPage',
    () => {
        it(
            'creates a new Leave Type from the inline form',
            async () => {
                let latestLeaveTypes: unknown[] = [];

                mockBaseEndpoints(
                    {
                        leaveTypes: latestLeaveTypes,
                    },
                );

                apiMockServer.use(
                    http.get(
                        '*/api/v1/hr/leave-types',
                        () =>
                            HttpResponse.json(
                                {
                                    status: 'success',
                                    data: latestLeaveTypes,
                                },
                            ),
                    ),
                    http.post(
                        '*/api/v1/hr/leave-types',
                        async ({
                            request,
                        }) => {
                            const body =
                                await request.json() as Record<string, unknown>;

                            expect(
                                body,
                            ).toEqual(
                                {
                                    code: 'CUTI_TAHUNAN',
                                    name: 'Cuti Tahunan',
                                    category: 'LEAVE',
                                    balance_mode: 'BALANCE',
                                    unit: 'DAY',
                                    description: null,
                                },
                            );

                            latestLeaveTypes = [
                                LEAVE_TYPE,
                            ];

                            return HttpResponse.json(
                                {
                                    status: 'success',
                                    data: LEAVE_TYPE,
                                },
                                {
                                    status: 201,
                                },
                            );
                        },
                    ),
                );

                renderPage();

                await screen.findByText(
                    'Belum ada Jenis Cuti terdaftar.',
                );

                changeById(
                    'leave-type-code',
                    'CUTI_TAHUNAN',
                );

                changeById(
                    'leave-type-name',
                    'Cuti Tahunan',
                );

                fireEvent.click(
                    screen.getAllByRole(
                        'button',
                        {
                            name: 'Simpan',
                        },
                    )[0]!,
                );

                await waitFor(
                    () => {
                        expect(
                            screen.getByRole(
                                'cell',
                                {
                                    name: 'Cuti Tahunan',
                                },
                            ),
                        ).toBeInTheDocument();
                    },
                );
            },
        );

        it(
            'creates a new Leave Entitlement Policy referencing an existing Leave Type',
            async () => {
                let latestPolicies: unknown[] = [];

                mockBaseEndpoints();

                apiMockServer.use(
                    http.get(
                        '*/api/v1/hr/leave-entitlement-policies',
                        () =>
                            HttpResponse.json(
                                {
                                    status: 'success',
                                    data: latestPolicies,
                                },
                            ),
                    ),
                    http.post(
                        '*/api/v1/hr/leave-entitlement-policies',
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
                                    period_basis: 'CALENDAR_YEAR',
                                    grant_units: 12,
                                    carryover_mode: 'NONE',
                                    carryover_limit_units: null,
                                    effective_from: '2026-01-01',
                                    effective_to: null,
                                    organization_id: null,
                                    organization_unit_id: null,
                                    employment_type_id: null,
                                    employment_classification_id: null,
                                },
                            );

                            const created = {
                                id: '01970000-0000-7000-8000-0000000091aa',
                                tenant_id: '01970000-0000-7000-8000-0000000000ff',
                                leave_type_id: LEAVE_TYPE.id,
                                organization_id: null,
                                organization_unit_id: null,
                                employment_type_id: null,
                                employment_classification_id: null,
                                period_basis: 'CALENDAR_YEAR',
                                grant_units: '12.00',
                                carryover_mode: 'NONE',
                                carryover_limit_units: null,
                                effective_from: '2026-01-01',
                                effective_to: null,
                                priority: 0,
                                is_active: true,
                                created_at: '2026-01-02T00:00:00Z',
                                updated_at: '2026-01-02T00:00:00Z',
                            };

                            latestPolicies = [
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

                renderPage();

                await screen.findByText(
                    'Belum ada Kebijakan Hak Cuti.',
                );

                changeById(
                    'entitlement-leave-type',
                    LEAVE_TYPE.id,
                );

                changeById(
                    'entitlement-grant-units',
                    '12',
                );

                changeById(
                    'entitlement-effective-from',
                    '2026-01-01',
                );

                fireEvent.click(
                    screen.getAllByRole(
                        'button',
                        {
                            name: 'Simpan',
                        },
                    )[1]!,
                );

                await waitFor(
                    () => {
                        expect(
                            screen.getByRole(
                                'cell',
                                {
                                    name: 'Cuti Tahunan',
                                },
                            ),
                        ).toBeInTheDocument();
                    },
                );
            },
        );

        it(
            'creates a new Leave Approval Policy with a dynamic step added',
            async () => {
                let latestPolicies: unknown[] = [];

                mockBaseEndpoints();

                apiMockServer.use(
                    http.get(
                        '*/api/v1/hr/leave-approval-policies',
                        () =>
                            HttpResponse.json(
                                {
                                    status: 'success',
                                    data: latestPolicies,
                                },
                            ),
                    ),
                    http.post(
                        '*/api/v1/hr/leave-approval-policies',
                        async ({
                            request,
                        }) => {
                            const body =
                                await request.json() as Record<string, unknown>;

                            expect(
                                body,
                            ).toEqual(
                                {
                                    policy_code: 'APPROVAL_STANDAR',
                                    name: 'Persetujuan Standar',
                                    leave_type_id: null,
                                    decision_mode: 'SEQUENTIAL',
                                    effective_from: '2026-01-01',
                                    effective_to: null,
                                    organization_id: null,
                                    organization_unit_id: null,
                                    employment_type_id: null,
                                    employment_classification_id: null,
                                    steps: [
                                        {
                                            step_order: 1,
                                            required_permission: 'hr.leave.approve',
                                            scope_strategy: 'ORGANIZATION',
                                            independent_approver: false,
                                        },
                                    ],
                                },
                            );

                            const created = {
                                id: '01970000-0000-7000-8000-0000000092aa',
                                tenant_id: '01970000-0000-7000-8000-0000000000ff',
                                policy_code: 'APPROVAL_STANDAR',
                                version_no: 1,
                                name: 'Persetujuan Standar',
                                leave_type_id: null,
                                organization_id: null,
                                organization_unit_id: null,
                                employment_type_id: null,
                                employment_classification_id: null,
                                decision_mode: 'SEQUENTIAL',
                                effective_from: '2026-01-01',
                                effective_to: null,
                                priority: 0,
                                is_active: true,
                                created_at: '2026-01-02T00:00:00Z',
                                updated_at: '2026-01-02T00:00:00Z',
                                steps: [
                                    {
                                        id: '01970000-0000-7000-8000-0000000093aa',
                                        approval_policy_id: '01970000-0000-7000-8000-0000000092aa',
                                        step_order: 1,
                                        required_permission: 'hr.leave.approve',
                                        scope_strategy: 'ORGANIZATION',
                                        independent_approver: false,
                                        created_at: '2026-01-02T00:00:00Z',
                                    },
                                ],
                            };

                            latestPolicies = [
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

                renderPage();

                await screen.findByText(
                    'Belum ada Kebijakan Persetujuan.',
                );

                changeById(
                    'approval-policy-code',
                    'APPROVAL_STANDAR',
                );

                changeById(
                    'approval-policy-name',
                    'Persetujuan Standar',
                );

                changeById(
                    'step-permission-0',
                    'hr.leave.approve',
                );

                changeById(
                    'approval-policy-effective-from',
                    '2026-01-01',
                );

                fireEvent.click(
                    screen.getAllByRole(
                        'button',
                        {
                            name: 'Simpan',
                        },
                    )[2]!,
                );

                await waitFor(
                    () => {
                        expect(
                            screen.getByRole(
                                'cell',
                                {
                                    name: 'Persetujuan Standar',
                                },
                            ),
                        ).toBeInTheDocument();
                    },
                );
            },
        );
    },
);
