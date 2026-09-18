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
        name: 'Recruitment Page Test Tenant',
    },
    workspaces: [],
    current: {
        type: 'TENANT' as const,
        organizational_assignment_id: null,
        organization_id: null,
        organization_unit_id: null,
        label: 'Recruitment Page Test Tenant',
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
    HrRecruitmentPage,
} = await import(
    '@/modules/hr/recruitment/HrRecruitmentPage'
);

const POSITION = {
    id: '01970000-0000-7000-8000-0000000d0aa',
    tenant_id: '01970000-0000-7000-8000-0000000000ff',
    code: 'GURU_MTK',
    name: 'Guru Matematika',
    description: null,
    is_active: true,
    created_at: '2026-01-01T00:00:00Z',
    updated_at: '2026-01-01T00:00:00Z',
};

const ORGANIZATION = {
    id: '01970000-0000-7000-8000-0000000d1aa',
    name: 'Yayasan Merdeka',
    code: 'YM',
    is_active: true,
    created_at: '2026-01-01T00:00:00Z',
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
                <HrRecruitmentPage />
            </QueryClientProvider>
        </ApiClientProvider>,
    );
}

describe(
    'HrRecruitmentPage',
    () => {
        it(
            'creates a DRAFT vacancy, submits it, then approves it',
            async () => {
                let currentVacancy: Record<string, unknown> | null = null;

                apiMockServer.use(
                    http.get(
                        '*/api/v1/hr/positions',
                        () =>
                            HttpResponse.json(
                                {
                                    status: 'success',
                                    data: [
                                        POSITION,
                                    ],
                                },
                            ),
                    ),
                    http.get(
                        '*/api/v1/core/organizations',
                        () =>
                            HttpResponse.json(
                                {
                                    status: 'success',
                                    data: [
                                        ORGANIZATION,
                                    ],
                                },
                            ),
                    ),
                    http.get(
                        `*/api/v1/core/organizations/${ORGANIZATION.id}/units`,
                        () =>
                            HttpResponse.json(
                                {
                                    status: 'success',
                                    data: [],
                                },
                            ),
                    ),
                    http.get(
                        '*/api/v1/hr/recruitment/vacancies',
                        () =>
                            HttpResponse.json(
                                {
                                    status: 'success',
                                    data:
                                        currentVacancy === null
                                            ? []
                                            : [
                                                currentVacancy,
                                            ],
                                    meta: {
                                        current_page: 1,
                                        last_page: 1,
                                        per_page: 15,
                                        total:
                                            currentVacancy === null
                                                ? 0
                                                : 1,
                                    },
                                },
                            ),
                    ),
                    http.get(
                        '*/api/v1/hr/recruitment/candidates',
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
                    http.post(
                        '*/api/v1/hr/recruitment/vacancies',
                        async ({
                            request,
                        }) => {
                            const body =
                                await request.json() as Record<string, unknown>;

                            expect(
                                body,
                            ).toMatchObject(
                                {
                                    code: 'GURU-2026-01',
                                    title: 'Guru Matematika SMA',
                                    position_id: POSITION.id,
                                    organization_id: ORGANIZATION.id,
                                    organization_unit_id: null,
                                    requested_headcount: 2,
                                    description: null,
                                },
                            );

                            currentVacancy = {
                                id: '01970000-0000-7000-8000-0000000d2aa',
                                tenant_id: '01970000-0000-7000-8000-0000000000ff',
                                code: 'GURU-2026-01',
                                title: 'Guru Matematika SMA',
                                position_id: POSITION.id,
                                organization_id: ORGANIZATION.id,
                                organization_unit_id: null,
                                requested_headcount: 2,
                                description: null,
                                status: 'DRAFT',
                                open_at: null,
                                close_at: null,
                                created_by_membership_id: '01970000-0000-7000-8000-0000000000ee',
                                created_at: '2026-01-20T00:00:00Z',
                                updated_at: '2026-01-20T00:00:00Z',
                            };

                            return HttpResponse.json(
                                {
                                    status: 'success',
                                    message: 'Vacancy created with DRAFT status.',
                                    data: currentVacancy,
                                },
                                {
                                    status: 201,
                                },
                            );
                        },
                    ),
                    http.post(
                        '*/api/v1/hr/recruitment/vacancies/:id/submit',
                        () => {
                            if (currentVacancy !== null) {
                                currentVacancy = {
                                    ...currentVacancy,
                                    status: 'PENDING_APPROVAL',
                                };
                            }

                            return HttpResponse.json(
                                {
                                    status: 'success',
                                    data: currentVacancy,
                                },
                            );
                        },
                    ),
                    http.post(
                        '*/api/v1/hr/recruitment/vacancies/:id/approve',
                        () => {
                            if (currentVacancy !== null) {
                                currentVacancy = {
                                    ...currentVacancy,
                                    status: 'APPROVED',
                                };
                            }

                            return HttpResponse.json(
                                {
                                    status: 'success',
                                    data: currentVacancy,
                                },
                            );
                        },
                    ),
                );

                renderPage();

                await screen.findByText(
                    'Belum ada Lowongan.',
                );

                changeById(
                    'vacancy-code',
                    'GURU-2026-01',
                );

                changeById(
                    'vacancy-title',
                    'Guru Matematika SMA',
                );

                changeById(
                    'vacancy-position',
                    POSITION.id,
                );

                changeById(
                    'vacancy-organization',
                    ORGANIZATION.id,
                );

                changeById(
                    'vacancy-headcount',
                    '2',
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
                                'Menunggu Persetujuan',
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

        it(
            'creates a new Candidate from the inline form',
            async () => {
                let currentCandidate: Record<string, unknown> | null = null;

                apiMockServer.use(
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
                        '*/api/v1/core/organizations',
                        () =>
                            HttpResponse.json(
                                {
                                    status: 'success',
                                    data: [],
                                },
                            ),
                    ),
                    http.get(
                        '*/api/v1/hr/recruitment/vacancies',
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
                        '*/api/v1/hr/recruitment/candidates',
                        () =>
                            HttpResponse.json(
                                {
                                    status: 'success',
                                    data:
                                        currentCandidate === null
                                            ? []
                                            : [
                                                currentCandidate,
                                            ],
                                    meta: {
                                        current_page: 1,
                                        last_page: 1,
                                        per_page: 15,
                                        total:
                                            currentCandidate === null
                                                ? 0
                                                : 1,
                                    },
                                },
                            ),
                    ),
                    http.post(
                        '*/api/v1/hr/recruitment/candidates',
                        async ({
                            request,
                        }) => {
                            const body =
                                await request.json() as Record<string, unknown>;

                            expect(
                                body,
                            ).toMatchObject(
                                {
                                    display_name: 'Budi Santoso',
                                    birth_date: null,
                                    primary_email: null,
                                    primary_phone: null,
                                    source: null,
                                },
                            );

                            currentCandidate = {
                                id: '01970000-0000-7000-8000-0000000d3aa',
                                tenant_id: '01970000-0000-7000-8000-0000000000ff',
                                person_id: null,
                                display_name: 'Budi Santoso',
                                birth_date: null,
                                primary_email: null,
                                normalized_email: null,
                                primary_phone: null,
                                normalized_phone: null,
                                source: null,
                                status: 'ACTIVE',
                                created_at: '2026-01-20T00:00:00Z',
                                updated_at: '2026-01-20T00:00:00Z',
                            };

                            return HttpResponse.json(
                                {
                                    status: 'success',
                                    message: 'Candidate created.',
                                    data: currentCandidate,
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
                    'Belum ada Kandidat.',
                );

                changeById(
                    'candidate-display-name',
                    'Budi Santoso',
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
                                'Budi Santoso',
                            ),
                        ).toBeInTheDocument();
                    },
                );
            },
        );

        it(
            'drives an Application through the full pipeline: submit, process, approve, hire-convert, onboard',
            async () => {
                const openVacancy = {
                    id: '01970000-0000-7000-8000-0000000d4aa',
                    tenant_id: '01970000-0000-7000-8000-0000000000ff',
                    code: 'GURU-2026-02',
                    title: 'Guru Fisika SMA',
                    position_id: POSITION.id,
                    organization_id: ORGANIZATION.id,
                    organization_unit_id: null,
                    requested_headcount: 1,
                    description: null,
                    status: 'OPEN',
                    open_at: '2026-01-20T00:00:00Z',
                    close_at: null,
                    created_by_membership_id: '01970000-0000-7000-8000-0000000000ee',
                    created_at: '2026-01-20T00:00:00Z',
                    updated_at: '2026-01-20T00:00:00Z',
                };

                const candidate = {
                    id: '01970000-0000-7000-8000-0000000d5aa',
                    tenant_id: '01970000-0000-7000-8000-0000000000ff',
                    person_id: null,
                    display_name: 'Siti Aminah',
                    birth_date: null,
                    primary_email: null,
                    normalized_email: null,
                    primary_phone: null,
                    normalized_phone: null,
                    source: null,
                    status: 'ACTIVE',
                    created_at: '2026-01-01T00:00:00Z',
                    updated_at: '2026-01-01T00:00:00Z',
                };

                const employmentType = {
                    id: '01970000-0000-7000-8000-0000000d6aa',
                    tenant_id: '01970000-0000-7000-8000-0000000000ff',
                    code: 'PNS',
                    name: 'Pegawai Tetap',
                    is_active: true,
                    created_at: '2026-01-01T00:00:00Z',
                    updated_at: '2026-01-01T00:00:00Z',
                };

                let currentApplication: Record<string, unknown> | null = null;

                apiMockServer.use(
                    http.get(
                        '*/api/v1/hr/positions',
                        () =>
                            HttpResponse.json(
                                {
                                    status: 'success',
                                    data: [
                                        POSITION,
                                    ],
                                },
                            ),
                    ),
                    http.get(
                        '*/api/v1/core/organizations',
                        () =>
                            HttpResponse.json(
                                {
                                    status: 'success',
                                    data: [
                                        ORGANIZATION,
                                    ],
                                },
                            ),
                    ),
                    http.get(
                        '*/api/v1/hr/employment-types',
                        () =>
                            HttpResponse.json(
                                {
                                    status: 'success',
                                    data: [
                                        employmentType,
                                    ],
                                },
                            ),
                    ),
                    http.get(
                        '*/api/v1/hr/recruitment/vacancies',
                        () =>
                            HttpResponse.json(
                                {
                                    status: 'success',
                                    data: [
                                        openVacancy,
                                    ],
                                    meta: {
                                        current_page: 1,
                                        last_page: 1,
                                        per_page: 15,
                                        total: 1,
                                    },
                                },
                            ),
                    ),
                    http.get(
                        '*/api/v1/hr/recruitment/candidates',
                        () =>
                            HttpResponse.json(
                                {
                                    status: 'success',
                                    data: [
                                        candidate,
                                    ],
                                    meta: {
                                        current_page: 1,
                                        last_page: 1,
                                        per_page: 15,
                                        total: 1,
                                    },
                                },
                            ),
                    ),
                    http.get(
                        `*/api/v1/hr/recruitment/vacancies/${openVacancy.id}/applications`,
                        () =>
                            HttpResponse.json(
                                {
                                    status: 'success',
                                    data:
                                        currentApplication === null
                                            ? []
                                            : [
                                                currentApplication,
                                            ],
                                    meta: {
                                        current_page: 1,
                                        last_page: 1,
                                        per_page: 15,
                                        total:
                                            currentApplication === null
                                                ? 0
                                                : 1,
                                    },
                                },
                            ),
                    ),
                    http.post(
                        `*/api/v1/hr/recruitment/vacancies/${openVacancy.id}/applications`,
                        async ({
                            request,
                        }) => {
                            const body =
                                await request.json() as Record<string, unknown>;

                            expect(
                                body,
                            ).toEqual(
                                {
                                    candidate_id: candidate.id,
                                },
                            );

                            currentApplication = {
                                id: '01970000-0000-7000-8000-0000000d7aa',
                                tenant_id: '01970000-0000-7000-8000-0000000000ff',
                                vacancy_id: openVacancy.id,
                                candidate_id: candidate.id,
                                status: 'SUBMITTED',
                                submitted_at: '2026-01-21T00:00:00Z',
                                finalized_at: null,
                                created_at: '2026-01-21T00:00:00Z',
                                updated_at: '2026-01-21T00:00:00Z',
                            };

                            return HttpResponse.json(
                                {
                                    status: 'success',
                                    message: 'Application submitted with SUBMITTED status.',
                                    data: currentApplication,
                                },
                                {
                                    status: 201,
                                },
                            );
                        },
                    ),
                    http.post(
                        '*/api/v1/hr/recruitment/applications/:id/start-processing',
                        () => {
                            if (currentApplication !== null) {
                                currentApplication = {
                                    ...currentApplication,
                                    status: 'IN_PROCESS',
                                };
                            }

                            return HttpResponse.json(
                                {
                                    status: 'success',
                                    data: currentApplication,
                                },
                            );
                        },
                    ),
                    http.post(
                        '*/api/v1/hr/recruitment/applications/:id/approve-for-hiring',
                        () => {
                            if (currentApplication !== null) {
                                currentApplication = {
                                    ...currentApplication,
                                    status: 'HIRING_APPROVED',
                                };
                            }

                            return HttpResponse.json(
                                {
                                    status: 'success',
                                    data: currentApplication,
                                },
                            );
                        },
                    ),
                    http.post(
                        '*/api/v1/hr/recruitment/applications/:id/hire-conversion',
                        async ({
                            request,
                        }) => {
                            const body =
                                await request.json() as Record<string, unknown>;

                            expect(
                                body,
                            ).toMatchObject(
                                {
                                    employment_type_id: employmentType.id,
                                    start_date: '2026-02-01',
                                    confirm_create_new_person: true,
                                },
                            );

                            if (currentApplication !== null) {
                                currentApplication = {
                                    ...currentApplication,
                                    status: 'HIRED',
                                };
                            }

                            return HttpResponse.json(
                                {
                                    status: 'success',
                                    message: 'Hiring conversion succeeded. Employee provisioned with PLANNED Employment.',
                                    data: {
                                        id: '01970000-0000-7000-8000-0000000d8aa',
                                        tenant_id: '01970000-0000-7000-8000-0000000000ff',
                                        application_id: '01970000-0000-7000-8000-0000000d7aa',
                                        resolution_status: 'CREATE_NEW_CONFIRMED',
                                        conversion_status: 'SUCCEEDED',
                                        person_id: '01970000-0000-7000-8000-0000000d9aa',
                                        membership_id: '01970000-0000-7000-8000-0000000daaa',
                                        employee_id: '01970000-0000-7000-8000-0000000dbaa',
                                        employment_id: '01970000-0000-7000-8000-0000000dcaa',
                                        resolved_by_membership_id: '01970000-0000-7000-8000-0000000000ee',
                                        converted_by_membership_id: '01970000-0000-7000-8000-0000000000ee',
                                        converted_at: '2026-02-01T00:00:00Z',
                                        created_at: '2026-01-25T00:00:00Z',
                                        updated_at: '2026-02-01T00:00:00Z',
                                    },
                                },
                            );
                        },
                    ),
                    http.post(
                        '*/api/v1/hr/recruitment/applications/:id/onboarding',
                        async ({
                            request,
                        }) => {
                            const body =
                                await request.json() as Record<string, unknown>;

                            expect(
                                body,
                            ).toEqual(
                                {
                                    template_id: null,
                                },
                            );

                            return HttpResponse.json(
                                {
                                    status: 'success',
                                    message: 'Onboarding Case created.',
                                    data: {
                                        id: '01970000-0000-7000-8000-0000000ddaa',
                                        tenant_id: '01970000-0000-7000-8000-0000000000ff',
                                        application_id: '01970000-0000-7000-8000-0000000d7aa',
                                        template_id: null,
                                        employee_id: '01970000-0000-7000-8000-0000000dbaa',
                                        employment_id: '01970000-0000-7000-8000-0000000dcaa',
                                        status: 'NOT_STARTED',
                                        started_at: null,
                                        completed_at: null,
                                        created_at: '2026-02-01T00:00:00Z',
                                        updated_at: '2026-02-01T00:00:00Z',
                                        tasks: [
                                            {
                                                id: '01970000-0000-7000-8000-0000000deaa',
                                                tenant_id: '01970000-0000-7000-8000-0000000000ff',
                                                onboarding_case_id: '01970000-0000-7000-8000-0000000ddaa',
                                                template_task_id: null,
                                                code: 'SIGN_CONTRACT',
                                                title: 'Tanda Tangan Kontrak',
                                                category: 'ADMINISTRATIVE',
                                                sequence: 1,
                                                is_required: true,
                                                requires_evidence: false,
                                                status: 'PENDING',
                                                completed_by_membership_id: null,
                                                completed_at: null,
                                                completion_note: null,
                                                created_at: '2026-02-01T00:00:00Z',
                                                updated_at: '2026-02-01T00:00:00Z',
                                            },
                                        ],
                                    },
                                },
                                {
                                    status: 201,
                                },
                            );
                        },
                    ),
                    http.get(
                        '*/api/v1/hr/onboarding/templates',
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
                    http.post(
                        '*/api/v1/hr/onboarding/cases/01970000-0000-7000-8000-0000000ddaa/start',
                        () =>
                            HttpResponse.json(
                                {
                                    status: 'success',
                                    data: {
                                        id: '01970000-0000-7000-8000-0000000ddaa',
                                        tenant_id: '01970000-0000-7000-8000-0000000000ff',
                                        application_id: '01970000-0000-7000-8000-0000000d7aa',
                                        template_id: null,
                                        employee_id: '01970000-0000-7000-8000-0000000dbaa',
                                        employment_id: '01970000-0000-7000-8000-0000000dcaa',
                                        status: 'IN_PROGRESS',
                                        started_at: '2026-02-02T00:00:00Z',
                                        completed_at: null,
                                        created_at: '2026-02-01T00:00:00Z',
                                        updated_at: '2026-02-02T00:00:00Z',
                                        tasks: [
                                            {
                                                id: '01970000-0000-7000-8000-0000000deaa',
                                                tenant_id: '01970000-0000-7000-8000-0000000000ff',
                                                onboarding_case_id: '01970000-0000-7000-8000-0000000ddaa',
                                                template_task_id: null,
                                                code: 'SIGN_CONTRACT',
                                                title: 'Tanda Tangan Kontrak',
                                                category: 'ADMINISTRATIVE',
                                                sequence: 1,
                                                is_required: true,
                                                requires_evidence: false,
                                                status: 'PENDING',
                                                completed_by_membership_id: null,
                                                completed_at: null,
                                                completion_note: null,
                                                created_at: '2026-02-01T00:00:00Z',
                                                updated_at: '2026-02-01T00:00:00Z',
                                            },
                                        ],
                                    },
                                },
                            ),
                    ),
                    http.post(
                        '*/api/v1/hr/onboarding/tasks/01970000-0000-7000-8000-0000000deaa/complete',
                        () =>
                            HttpResponse.json(
                                {
                                    status: 'success',
                                    data: {
                                        id: '01970000-0000-7000-8000-0000000deaa',
                                        tenant_id: '01970000-0000-7000-8000-0000000000ff',
                                        onboarding_case_id: '01970000-0000-7000-8000-0000000ddaa',
                                        template_task_id: null,
                                        code: 'SIGN_CONTRACT',
                                        title: 'Tanda Tangan Kontrak',
                                        category: 'ADMINISTRATIVE',
                                        sequence: 1,
                                        is_required: true,
                                        requires_evidence: false,
                                        status: 'COMPLETED',
                                        completed_by_membership_id: '01970000-0000-7000-8000-0000000000ee',
                                        completed_at: '2026-02-03T00:00:00Z',
                                        completion_note: null,
                                        created_at: '2026-02-01T00:00:00Z',
                                        updated_at: '2026-02-03T00:00:00Z',
                                    },
                                },
                            ),
                    ),
                );

                renderPage();

                await screen.findByText(
                    'Guru Fisika SMA',
                );

                fireEvent.click(
                    screen.getByRole(
                        'button',
                        {
                            name: 'Lihat Lamaran',
                        },
                    ),
                );

                await screen.findByText(
                    'Belum ada Lamaran untuk Lowongan ini.',
                );

                changeById(
                    `application-candidate-${openVacancy.id}`,
                    candidate.id,
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
                            name: 'Proses',
                        },
                    ),
                );

                await waitFor(
                    () => {
                        expect(
                            screen.getByText(
                                'Diproses',
                            ),
                        ).toBeInTheDocument();
                    },
                );

                fireEvent.click(
                    screen.getByRole(
                        'button',
                        {
                            name: 'Setujui untuk Rekrut',
                        },
                    ),
                );

                await waitFor(
                    () => {
                        expect(
                            screen.getByText(
                                'Disetujui untuk Perekrutan',
                            ),
                        ).toBeInTheDocument();
                    },
                );

                await screen.findByText(
                    employmentType.name,
                );

                changeById(
                    `hire-employment-type-01970000-0000-7000-8000-0000000d7aa`,
                    employmentType.id,
                );

                changeById(
                    `hire-start-date-01970000-0000-7000-8000-0000000d7aa`,
                    '2026-02-01',
                );

                const confirmCheckbox =
                    document.getElementById(
                        'hire-confirm-01970000-0000-7000-8000-0000000d7aa',
                    );

                if (confirmCheckbox === null) {
                    throw new Error(
                        'Expected the hire-confirm checkbox to be present.',
                    );
                }

                fireEvent.click(
                    confirmCheckbox,
                );

                fireEvent.click(
                    screen.getByRole(
                        'button',
                        {
                            name: 'Proses Perekrutan',
                        },
                    ),
                );

                await waitFor(
                    () => {
                        expect(
                            screen.getByText(
                                (
                                    _content,
                                    element,
                                ) =>
                                    element?.textContent === 'Status konversi: SUCCEEDED',
                            ),
                        ).toBeInTheDocument();
                    },
                );

                await waitFor(
                    () => {
                        expect(
                            screen.getByText(
                                'Direkrut',
                            ),
                        ).toBeInTheDocument();
                    },
                );

                fireEvent.click(
                    screen.getByRole(
                        'button',
                        {
                            name: 'Mulai Onboarding',
                        },
                    ),
                );

                await waitFor(
                    () => {
                        expect(
                            screen.getByText(
                                'Belum Dimulai',
                            ),
                        ).toBeInTheDocument();
                    },
                );

                fireEvent.click(
                    screen.getByRole(
                        'button',
                        {
                            name: 'Mulai',
                        },
                    ),
                );

                await waitFor(
                    () => {
                        expect(
                            screen.getByText(
                                'Berjalan',
                            ),
                        ).toBeInTheDocument();
                    },
                );

                fireEvent.click(
                    screen.getByRole(
                        'button',
                        {
                            name: 'Selesaikan',
                        },
                    ),
                );

                await waitFor(
                    () => {
                        expect(
                            screen.getByText(
                                'Selesai',
                            ),
                        ).toBeInTheDocument();
                    },
                );
            },
        );
    },
);
