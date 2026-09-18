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
        name: 'Onboarding Templates Page Test Tenant',
    },
    workspaces: [],
    current: {
        type: 'TENANT' as const,
        organizational_assignment_id: null,
        organization_id: null,
        organization_unit_id: null,
        label: 'Onboarding Templates Page Test Tenant',
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
    HrOnboardingTemplatesPage,
} = await import(
    '@/modules/hr/onboarding/HrOnboardingTemplatesPage'
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
                <HrOnboardingTemplatesPage />
            </QueryClientProvider>
        </ApiClientProvider>,
    );
}

describe(
    'HrOnboardingTemplatesPage',
    () => {
        it(
            'creates a new Onboarding Template with one dynamic task row',
            async () => {
                let currentTemplate: Record<string, unknown> | null = null;

                apiMockServer.use(
                    http.get(
                        '*/api/v1/hr/onboarding/templates',
                        () =>
                            HttpResponse.json(
                                {
                                    status: 'success',
                                    data:
                                        currentTemplate === null
                                            ? []
                                            : [
                                                currentTemplate,
                                            ],
                                    meta: {
                                        current_page: 1,
                                        last_page: 1,
                                        per_page: 15,
                                        total:
                                            currentTemplate === null
                                                ? 0
                                                : 1,
                                    },
                                },
                            ),
                    ),
                    http.post(
                        '*/api/v1/hr/onboarding/templates',
                        async ({
                            request,
                        }) => {
                            const body =
                                await request.json() as Record<string, unknown>;

                            expect(
                                body,
                            ).toEqual(
                                {
                                    code: 'STANDARD',
                                    name: 'Onboarding Standar',
                                    tasks: [
                                        {
                                            code: 'SIGN_CONTRACT',
                                            title: 'Tanda Tangan Kontrak',
                                            category: 'CONTRACT',
                                            sequence: 1,
                                            is_required: true,
                                            requires_evidence: false,
                                        },
                                    ],
                                },
                            );

                            currentTemplate = {
                                id: '01970000-0000-7000-8000-0000000e0aa',
                                tenant_id: '01970000-0000-7000-8000-0000000000ff',
                                code: 'STANDARD',
                                name: 'Onboarding Standar',
                                is_active: true,
                                created_at: '2026-02-01T00:00:00Z',
                                updated_at: '2026-02-01T00:00:00Z',
                                tasks: [
                                    {
                                        id: '01970000-0000-7000-8000-0000000e1aa',
                                        tenant_id: '01970000-0000-7000-8000-0000000000ff',
                                        template_id: '01970000-0000-7000-8000-0000000e0aa',
                                        code: 'SIGN_CONTRACT',
                                        title: 'Tanda Tangan Kontrak',
                                        category: 'CONTRACT',
                                        sequence: 1,
                                        is_required: true,
                                        requires_evidence: false,
                                        created_at: '2026-02-01T00:00:00Z',
                                        updated_at: '2026-02-01T00:00:00Z',
                                    },
                                ],
                            };

                            return HttpResponse.json(
                                {
                                    status: 'success',
                                    message: 'Onboarding template created.',
                                    data: {
                                        id: '01970000-0000-7000-8000-0000000e0aa',
                                        tenant_id: '01970000-0000-7000-8000-0000000000ff',
                                        code: 'STANDARD',
                                        name: 'Onboarding Standar',
                                        is_active: true,
                                        created_at: '2026-02-01T00:00:00Z',
                                        updated_at: '2026-02-01T00:00:00Z',
                                        tasks: [
                                            {
                                                id: '01970000-0000-7000-8000-0000000e1aa',
                                                tenant_id: '01970000-0000-7000-8000-0000000000ff',
                                                template_id: '01970000-0000-7000-8000-0000000e0aa',
                                                code: 'SIGN_CONTRACT',
                                                title: 'Tanda Tangan Kontrak',
                                                category: 'CONTRACT',
                                                sequence: 1,
                                                is_required: true,
                                                requires_evidence: false,
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
                );

                renderPage();

                await screen.findByText(
                    'Belum ada Template Onboarding.',
                );

                changeById(
                    'onboarding-template-code',
                    'STANDARD',
                );

                changeById(
                    'onboarding-template-name',
                    'Onboarding Standar',
                );

                changeById(
                    'onboarding-task-code-0',
                    'SIGN_CONTRACT',
                );

                changeById(
                    'onboarding-task-title-0',
                    'Tanda Tangan Kontrak',
                );

                changeById(
                    'onboarding-task-category-0',
                    'CONTRACT',
                );

                fireEvent.click(
                    screen.getByRole(
                        'button',
                        {
                            name: 'Simpan Template',
                        },
                    ),
                );

                await waitFor(
                    () => {
                        expect(
                            screen.getByText(
                                'Onboarding Standar',
                            ),
                        ).toBeInTheDocument();
                    },
                );

                expect(
                    screen.getByText(
                        (
                            _content,
                            element,
                        ) =>
                            element?.tagName.toLowerCase() === 'li'
                            && element.textContent === '1. Tanda Tangan Kontrak (CONTRACT, wajib)',
                    ),
                ).toBeInTheDocument();
            },
        );
    },
);
