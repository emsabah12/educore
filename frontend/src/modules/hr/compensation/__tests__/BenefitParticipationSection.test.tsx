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
        name: 'Benefit Participation Section Test Tenant',
    },
    workspaces: [],
    current: {
        type: 'TENANT' as const,
        organizational_assignment_id: null,
        organization_id: null,
        organization_unit_id: null,
        label: 'Benefit Participation Section Test Tenant',
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
    BenefitParticipationSection,
} = await import(
    '@/modules/hr/compensation/BenefitParticipationSection'
);

const EMPLOYMENT_ID =
    '01970000-0000-7000-8000-0000000040aa';

const PROGRAM = {
    id: '01970000-0000-7000-8000-0000000041aa',
    tenant_id: '01970000-0000-7000-8000-0000000000ff',
    code: 'BPJS_KESEHATAN',
    name: 'BPJS Kesehatan',
    category: 'STATUTORY',
    beneficiary_scope: 'EMPLOYEE',
    payroll_relevance: 'NONE',
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
                <BenefitParticipationSection
                    employmentId={
                        EMPLOYMENT_ID
                    }
                />
            </QueryClientProvider>
        </ApiClientProvider>,
    );
}

describe(
    'BenefitParticipationSection',
    () => {
        it(
            'registers a new participation and enrolls it',
            async () => {
                let currentStatus = '';

                const baseParticipation = {
                    id: '01970000-0000-7000-8000-0000000042aa',
                    employment_id: EMPLOYMENT_ID,
                    benefit_program_id: PROGRAM.id,
                    beneficiary_person_id: null,
                    effective_from: '2026-01-01',
                    effective_to: null,
                    verified_at: null,
                    verified_by_membership_id: null,
                    notes: null,
                };

                apiMockServer.use(
                    http.get(
                        `*/api/v1/hr/employments/${EMPLOYMENT_ID}/benefit-participations`,
                        () =>
                            HttpResponse.json(
                                {
                                    status: 'success',
                                    data:
                                        currentStatus === ''
                                            ? []
                                            : [
                                                {
                                                    ...baseParticipation,
                                                    status: currentStatus,
                                                },
                                            ],
                                },
                            ),
                    ),
                    http.get(
                        '*/api/v1/hr/benefits/programs',
                        () =>
                            HttpResponse.json(
                                {
                                    status: 'success',
                                    data: [
                                        PROGRAM,
                                    ],
                                },
                            ),
                    ),
                    http.post(
                        `*/api/v1/hr/employments/${EMPLOYMENT_ID}/benefit-participations`,
                        async ({
                            request,
                        }) => {
                            const body =
                                await request.json() as Record<string, unknown>;

                            expect(
                                body,
                            ).toEqual(
                                {
                                    benefit_program_id: PROGRAM.id,
                                    effective_from: '2026-01-01',
                                    effective_to: null,
                                    notes: null,
                                    beneficiary_person_id: null,
                                },
                            );

                            currentStatus = 'ELIGIBLE';

                            return HttpResponse.json(
                                {
                                    status: 'success',
                                    data: {
                                        ...baseParticipation,
                                        status: 'ELIGIBLE',
                                    },
                                },
                                {
                                    status: 201,
                                },
                            );
                        },
                    ),
                    http.post(
                        `*/api/v1/hr/employments/${EMPLOYMENT_ID}/benefit-participations/${baseParticipation.id}/enroll`,
                        () => {
                            currentStatus = 'ENROLLED';

                            return HttpResponse.json(
                                {
                                    status: 'success',
                                    data: {
                                        ...baseParticipation,
                                        status: 'ENROLLED',
                                    },
                                },
                            );
                        },
                    ),
                );

                renderSection();

                await screen.findByText(
                    'Belum ada kepesertaan benefit.',
                );

                fireEvent.change(
                    screen.getByLabelText(
                        'Program',
                    ),
                    {
                        target: {
                            value: PROGRAM.id,
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
                            name: 'Daftarkan',
                        },
                    ),
                );

                await waitFor(
                    () => {
                        expect(
                            screen.getByText(
                                'Berhak',
                            ),
                        ).toBeInTheDocument();
                    },
                );

                fireEvent.click(
                    screen.getByRole(
                        'button',
                        {
                            name: 'Verifikasi',
                        },
                    ),
                );

                await waitFor(
                    () => {
                        expect(
                            screen.getByText(
                                'Terdaftar',
                            ),
                        ).toBeInTheDocument();
                    },
                );
            },
        );

        it(
            'expands the identifiers panel and adds a new identifier',
            async () => {
                const participation = {
                    id: '01970000-0000-7000-8000-0000000043aa',
                    employment_id: EMPLOYMENT_ID,
                    benefit_program_id: PROGRAM.id,
                    beneficiary_person_id: null,
                    status: 'ENROLLED',
                    effective_from: '2026-01-01',
                    effective_to: null,
                    verified_at: '2026-01-02T00:00:00+00:00',
                    verified_by_membership_id: READY_TENANT_WORKSPACE_STATE.context.membership.id,
                    notes: null,
                };

                let latestIdentifiers: unknown[] = [];

                apiMockServer.use(
                    http.get(
                        `*/api/v1/hr/employments/${EMPLOYMENT_ID}/benefit-participations`,
                        () =>
                            HttpResponse.json(
                                {
                                    status: 'success',
                                    data: [
                                        participation,
                                    ],
                                },
                            ),
                    ),
                    http.get(
                        '*/api/v1/hr/benefits/programs',
                        () =>
                            HttpResponse.json(
                                {
                                    status: 'success',
                                    data: [
                                        PROGRAM,
                                    ],
                                },
                            ),
                    ),
                    http.get(
                        `*/api/v1/hr/benefit-participations/${participation.id}/identifiers`,
                        () =>
                            HttpResponse.json(
                                {
                                    status: 'success',
                                    data: latestIdentifiers,
                                },
                            ),
                    ),
                    http.post(
                        `*/api/v1/hr/benefit-participations/${participation.id}/identifiers`,
                        async ({
                            request,
                        }) => {
                            const body =
                                await request.json() as Record<string, unknown>;

                            expect(
                                body,
                            ).toEqual(
                                {
                                    identifier_type: 'BPJS_KESEHATAN',
                                    value: '0001112223334',
                                    issuer: null,
                                    issued_at: null,
                                    expires_at: null,
                                },
                            );

                            latestIdentifiers = [
                                {
                                    identifier_type: 'BPJS_KESEHATAN',
                                    value: '0001112223334',
                                    issuer: null,
                                },
                            ];

                            return HttpResponse.json(
                                {
                                    status: 'success',
                                    data: {
                                        id: '01970000-0000-7000-8000-0000000044aa',
                                        employee_benefit_participation_id: participation.id,
                                        identifier_type: 'BPJS_KESEHATAN',
                                        status: 'ACTIVE',
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

                await screen.findByRole(
                    'cell',
                    {
                        name: 'BPJS Kesehatan',
                    },
                );

                fireEvent.click(
                    screen.getByRole(
                        'button',
                        {
                            name: 'Kelola Nomor',
                        },
                    ),
                );

                await screen.findByText(
                    'Belum ada nomor identitas terdaftar.',
                );

                fireEvent.change(
                    screen.getByLabelText(
                        'Jenis',
                    ),
                    {
                        target: {
                            value: 'BPJS_KESEHATAN',
                        },
                    },
                );

                fireEvent.change(
                    screen.getByLabelText(
                        'Nomor',
                    ),
                    {
                        target: {
                            value: '0001112223334',
                        },
                    },
                );

                fireEvent.click(
                    screen.getByRole(
                        'button',
                        {
                            name: 'Tambah Nomor',
                        },
                    ),
                );

                await waitFor(
                    () => {
                        expect(
                            screen.getByRole(
                                'listitem',
                            ),
                        ).toHaveTextContent(
                            'BPJS_KESEHATAN: 0001112223334',
                        );
                    },
                );
            },
        );
    },
);
