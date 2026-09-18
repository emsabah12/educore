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
        name: 'Leave Request Section Test Tenant',
    },
    workspaces: [],
    current: {
        type: 'TENANT' as const,
        organizational_assignment_id: null,
        organization_id: null,
        organization_unit_id: null,
        label: 'Leave Request Section Test Tenant',
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
    LeaveRequestSection,
} = await import(
    '@/modules/hr/leave/LeaveRequestSection'
);

const EMPLOYMENT_ID =
    '01970000-0000-7000-8000-00000000a0aa';

const LEAVE_TYPE = {
    id: '01970000-0000-7000-8000-00000000a1aa',
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
                <LeaveRequestSection
                    employmentId={
                        EMPLOYMENT_ID
                    }
                />
            </QueryClientProvider>
        </ApiClientProvider>,
    );
}

describe(
    'LeaveRequestSection',
    () => {
        it(
            'creates a DRAFT request, submits it, then approves it',
            async () => {
                let currentRequest: Record<string, unknown> | null = null;

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
                        '*/api/v1/hr/leave-requests',
                        () =>
                            HttpResponse.json(
                                {
                                    status: 'success',
                                    data:
                                        currentRequest === null
                                            ? []
                                            : [
                                                currentRequest,
                                            ],
                                    meta: {
                                        current_page: 1,
                                        last_page: 1,
                                        per_page: 15,
                                        total:
                                            currentRequest === null
                                                ? 0
                                                : 1,
                                    },
                                },
                            ),
                    ),
                    http.post(
                        '*/api/v1/hr/leave-requests',
                        async ({
                            request,
                        }) => {
                            const body =
                                await request.json() as Record<string, unknown>;

                            expect(
                                body,
                            ).toMatchObject(
                                {
                                    employment_id: EMPLOYMENT_ID,
                                    leave_type_id: LEAVE_TYPE.id,
                                    starts_at: '2026-02-01',
                                    ends_at: '2026-02-03',
                                    requested_units: 3,
                                    reason: null,
                                },
                            );

                            currentRequest = {
                                id: '01970000-0000-7000-8000-00000000a2aa',
                                tenant_id: '01970000-0000-7000-8000-0000000000ff',
                                employment_id: EMPLOYMENT_ID,
                                leave_type_id: LEAVE_TYPE.id,
                                approval_context_placement_id: null,
                                approval_policy_id: null,
                                submitted_by_membership_id: null,
                                status: 'DRAFT',
                                starts_at: '2026-02-01',
                                ends_at: '2026-02-03',
                                request_timezone: 'Asia/Jakarta',
                                requested_units: '3.00',
                                unit: 'DAY',
                                reason: null,
                                submitted_at: null,
                                final_decided_at: null,
                                withdrawn_at: null,
                                cancelled_at: null,
                                created_at: '2026-01-15T00:00:00Z',
                                updated_at: '2026-01-15T00:00:00Z',
                            };

                            return HttpResponse.json(
                                {
                                    status: 'success',
                                    data: currentRequest,
                                },
                                {
                                    status: 201,
                                },
                            );
                        },
                    ),
                    http.post(
                        '*/api/v1/hr/leave-requests/:id/submit',
                        () => {
                            if (currentRequest !== null) {
                                currentRequest = {
                                    ...currentRequest,
                                    status: 'SUBMITTED',
                                };
                            }

                            return HttpResponse.json(
                                {
                                    status: 'success',
                                    data: currentRequest,
                                },
                            );
                        },
                    ),
                    http.post(
                        '*/api/v1/hr/leave-requests/:id/approve',
                        () => {
                            if (currentRequest !== null) {
                                currentRequest = {
                                    ...currentRequest,
                                    status: 'APPROVED',
                                };
                            }

                            return HttpResponse.json(
                                {
                                    status: 'success',
                                    data: currentRequest,
                                },
                            );
                        },
                    ),
                );

                renderSection();

                await screen.findByText(
                    'Belum ada pengajuan cuti untuk Employment ini.',
                );

                changeById(
                    'leave-request-leave-type',
                    LEAVE_TYPE.id,
                );

                changeById(
                    'leave-request-starts-at',
                    '2026-02-01',
                );

                changeById(
                    'leave-request-ends-at',
                    '2026-02-03',
                );

                changeById(
                    'leave-request-units',
                    '3',
                );

                fireEvent.click(
                    screen.getByRole(
                        'button',
                        {
                            name: 'Simpan sebagai Draf',
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
