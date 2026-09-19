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
        name: 'Self Leave Requests Page Test Tenant',
    },
    workspaces: [],
    current: {
        type: 'TENANT' as const,
        organizational_assignment_id: null,
        organization_id: null,
        organization_unit_id: null,
        label: 'Self Leave Requests Page Test Tenant',
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
    HrSelfLeaveRequestsPage,
} = await import(
    '@/modules/hr/leave/HrSelfLeaveRequestsPage'
);

const LEAVE_TYPE = {
    id: '01970000-0000-7000-8000-0000000b0aa',
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
                <HrSelfLeaveRequestsPage />
            </QueryClientProvider>
        </ApiClientProvider>,
    );
}

describe(
    'HrSelfLeaveRequestsPage',
    () => {
        it(
            'creates a DRAFT request, submits it, then withdraws it',
            async () => {
                let currentRequest: Record<string, unknown> | null = null;

                apiMockServer.use(
                    http.get(
                        '*/api/v1/hr/self/leave-balances',
                        () =>
                            HttpResponse.json(
                                {
                                    status: 'success',
                                    data: [],
                                },
                            ),
                    ),
                    http.get(
                        '*/api/v1/hr/self/leave-types',
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
                        '*/api/v1/hr/self/leave-requests',
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
                                },
                            ),
                    ),
                    http.post(
                        '*/api/v1/hr/self/leave-requests',
                        async ({
                            request,
                        }) => {
                            const body =
                                await request.json() as Record<string, unknown>;

                            expect(
                                body,
                            ).toMatchObject(
                                {
                                    leave_type_id: LEAVE_TYPE.id,
                                    starts_at: '2026-03-01',

                                    // §Perbaikan bug tanggal Selesai
                                    // -- user mengetik 2026-03-03
                                    // (hari TERAKHIR cuti, inklusif),
                                    // tapi backend pakai konvensi
                                    // rentang setengah-terbuka
                                    // (INV-HR-LEAVE-013), jadi yang
                                    // benar-benar dikirim adalah
                                    // 2026-03-04 (h+1, eksklusif).
                                    ends_at: '2026-03-04',
                                    requested_units: 3,
                                    reason: null,
                                },
                            );

                            currentRequest = {
                                id: '01970000-0000-7000-8000-0000000b1aa',
                                tenant_id: '01970000-0000-7000-8000-0000000000ff',
                                employment_id: '01970000-0000-7000-8000-0000000b2aa',
                                leave_type_id: LEAVE_TYPE.id,
                                approval_context_placement_id: null,
                                approval_policy_id: null,
                                submitted_by_membership_id: null,
                                status: 'DRAFT',
                                starts_at: '2026-03-01',
                                ends_at: '2026-03-03',
                                request_timezone: 'Asia/Jakarta',
                                requested_units: '3.00',
                                unit: 'DAY',
                                reason: null,
                                submitted_at: null,
                                final_decided_at: null,
                                withdrawn_at: null,
                                cancelled_at: null,
                                created_at: '2026-01-20T00:00:00Z',
                                updated_at: '2026-01-20T00:00:00Z',
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
                        '*/api/v1/hr/self/leave-requests/:id/submit',
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
                        '*/api/v1/hr/self/leave-requests/:id/withdraw',
                        () => {
                            if (currentRequest !== null) {
                                currentRequest = {
                                    ...currentRequest,
                                    status: 'WITHDRAWN',
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

                renderPage();

                await screen.findByText(
                    'Belum ada pengajuan cuti.',
                );

                changeById(
                    'self-leave-request-leave-type',
                    LEAVE_TYPE.id,
                );

                changeById(
                    'self-leave-request-starts-at',
                    '2026-03-01',
                );

                changeById(
                    'self-leave-request-ends-at',
                    '2026-03-03',
                );

                changeById(
                    'self-leave-request-units',
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
                            name: 'Tarik',
                        },
                    ),
                );

                await waitFor(
                    () => {
                        expect(
                            screen.getByText(
                                'Ditarik',
                            ),
                        ).toBeInTheDocument();
                    },
                );
            },
        );

        it(
            'creates a same-day (single-day) leave request without a rejection from the backend',
            async () => {
                // §Perbaikan bug tanggal Selesai -- sebelumnya cuti
                // 1 hari (Mulai=Selesai) SELALU gagal ("Gagal
                // menyimpan. Coba lagi.") karena backend menolak
                // ends_at yang tidak strictly lebih besar dari
                // starts_at. Test ini membuktikan skenario itu
                // sekarang berhasil.
                // §Perbaikan bug tanggal Selesai -- sebelumnya cuti
                // 1 hari (Mulai=Selesai) SELALU gagal ("Gagal
                // menyimpan. Coba lagi.") karena backend menolak
                // ends_at yang tidak strictly lebih besar dari
                // starts_at. Test ini membuktikan skenario itu
                // sekarang berhasil.
                let currentRequest: Record<string, unknown> | null = null;

                apiMockServer.use(
                    http.get(
                        '*/api/v1/hr/self/leave-balances',
                        () =>
                            HttpResponse.json(
                                {
                                    status: 'success',
                                    data: [],
                                },
                            ),
                    ),
                    http.get(
                        '*/api/v1/hr/self/leave-types',
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
                        '*/api/v1/hr/self/leave-requests',
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
                                },
                            ),
                    ),
                    http.post(
                        '*/api/v1/hr/self/leave-requests',
                        async ({
                            request,
                        }) => {
                            const body =
                                await request.json() as Record<string, unknown>;

                            expect(
                                body,
                            ).toMatchObject(
                                {
                                    leave_type_id: LEAVE_TYPE.id,

                                    // Mulai=Selesai=2026-04-10 (satu
                                    // hari) -- WAJIB dikirim sebagai
                                    // ends_at h+1 (2026-04-11), bukan
                                    // 2026-04-10, supaya
                                    // ends_at > starts_at terpenuhi
                                    // di backend.
                                    starts_at: '2026-04-10',
                                    ends_at: '2026-04-11',
                                    requested_units: 1,
                                },
                            );

                            currentRequest = {
                                id: '01970000-0000-7000-8000-0000000b3aa',
                                tenant_id: '01970000-0000-7000-8000-0000000000ff',
                                employment_id: '01970000-0000-7000-8000-0000000b2aa',
                                leave_type_id: LEAVE_TYPE.id,
                                approval_context_placement_id: null,
                                approval_policy_id: null,
                                submitted_by_membership_id: null,
                                status: 'DRAFT',
                                starts_at: '2026-04-10',
                                ends_at: '2026-04-11',
                                request_timezone: 'Asia/Jakarta',
                                requested_units: '1.00',
                                unit: 'DAY',
                                reason: null,
                                submitted_at: null,
                                final_decided_at: null,
                                withdrawn_at: null,
                                cancelled_at: null,
                                created_at: '2026-01-20T00:00:00Z',
                                updated_at: '2026-01-20T00:00:00Z',
                            };

                            return HttpResponse.json(
                                {
                                    status: 'success',
                                    message: 'Leave request created.',
                                    data: currentRequest,
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
                    'Belum ada pengajuan cuti.',
                );

                changeById(
                    'self-leave-request-leave-type',
                    LEAVE_TYPE.id,
                );

                changeById(
                    'self-leave-request-starts-at',
                    '2026-04-10',
                );

                changeById(
                    'self-leave-request-ends-at',
                    '2026-04-10',
                );

                changeById(
                    'self-leave-request-units',
                    '1',
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

                expect(
                    screen.queryByText(
                        'Gagal menyimpan. Coba lagi.',
                    ),
                ).not.toBeInTheDocument();
            },
        );
    },
);
