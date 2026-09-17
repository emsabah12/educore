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
    MemoryRouter,
} from 'react-router';
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
        name: 'Workforce Test Tenant',
    },
    workspaces: [],
    current: {
        type: 'ORGANIZATION' as const,
        organizational_assignment_id:
            '01970000-0000-7000-8000-0000000000cc',
        organization_id:
            '01970000-0000-7000-8000-0000000000dd',
        organization_unit_id: null,
        label: 'Workforce Test Organization',
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

/*
 * Imported AFTER the mock above so the module under test
 * resolves the mocked workspace hook rather than the real
 * Provider-backed implementation.
 */
const {
    HrWorkforcePage,
} = await import(
    '@/modules/hr/workforce/HrWorkforcePage'
);

function renderWorkforcePage() {
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
                <MemoryRouter>
                    <HrWorkforcePage />
                </MemoryRouter>
            </QueryClientProvider>
        </ApiClientProvider>,
    );
}

describe(
    'HrWorkforcePage',
    () => {
        it(
            'renders employees returned by the workspace listing endpoint',
            async () => {
                apiMockServer.use(
                    http.get(
                        '*/api/v1/hr/workspace/employees',
                        () =>
                            HttpResponse.json(
                                {
                                    status: 'success',
                                    data: [
                                        {
                                            id: '01970000-0000-7000-8000-0000000000ee',
                                            tenant_id:
                                                '01970000-0000-7000-8000-0000000000bb',
                                            membership_id:
                                                '01970000-0000-7000-8000-0000000000ff',
                                            nip: 'NIP-001',
                                            jabatan: 'GURU',
                                            nama: 'Budi Santoso',
                                            created_at:
                                                '2026-01-01T00:00:00Z',
                                        },
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
                );

                renderWorkforcePage();

                expect(
                    await screen.findByText(
                        'Budi Santoso',
                    ),
                ).toBeInTheDocument();

                expect(
                    screen.getByText(
                        'NIP-001',
                    ),
                ).toBeInTheDocument();

                expect(
                    screen.getAllByText(
                        'Guru',
                    ).length,
                ).toBeGreaterThanOrEqual(
                    1,
                );
            },
        );

        it(
            'creates a new employee from the inline form and clears it on success',
            async () => {
                apiMockServer.use(
                    http.get(
                        '*/api/v1/hr/workspace/employees',
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
                        '*/api/v1/hr/employment-types',
                        () =>
                            HttpResponse.json(
                                {
                                    status: 'success',
                                    data: [
                                        {
                                            id: '01970000-0000-7000-8000-0000000009aa',
                                            code: 'TETAP',
                                            name: 'Tetap',
                                            description: null,
                                            is_active: true,
                                        },
                                    ],
                                },
                            ),
                    ),
                    http.post(
                        '*/api/v1/hr/workspace/employees',
                        async ({
                            request,
                        }) => {
                            const body =
                                await request.json() as Record<string, unknown>;

                            expect(
                                body,
                            ).toEqual(
                                {
                                    nama: 'Dewi Lestari',
                                    nip: 'NIP-999',
                                    jabatan: 'STAFF',
                                    employment_type_id:
                                        '01970000-0000-7000-8000-0000000009aa',
                                },
                            );

                            return HttpResponse.json(
                                {
                                    status: 'success',
                                    message:
                                        'Employee provisioned within workspace with ACTIVE Employment and open Placement.',
                                    data: {
                                        employee_id:
                                            '01970000-0000-7000-8000-0000000009bb',
                                        membership_id:
                                            '01970000-0000-7000-8000-0000000009cc',
                                        employment_id:
                                            '01970000-0000-7000-8000-0000000009dd',
                                        organizational_assignment_id:
                                            '01970000-0000-7000-8000-0000000000cc',
                                        employment_placement_id:
                                            '01970000-0000-7000-8000-0000000009ee',
                                    },
                                },
                                {
                                    status: 201,
                                },
                            );
                        },
                    ),
                );

                renderWorkforcePage();

                await screen.findByText(
                    'Belum ada pegawai yang terlihat di workspace ini.',
                );

                fireEvent.change(
                    screen.getByLabelText(
                        'Nama',
                    ),
                    {
                        target: {
                            value: 'Dewi Lestari',
                        },
                    },
                );

                fireEvent.change(
                    screen.getByLabelText(
                        'NIP',
                    ),
                    {
                        target: {
                            value: 'NIP-999',
                        },
                    },
                );

                fireEvent.change(
                    screen.getByLabelText(
                        'Jabatan',
                    ),
                    {
                        target: {
                            value: 'STAFF',
                        },
                    },
                );

                await waitFor(
                    () => {
                        expect(
                            screen.getByLabelText(
                                'Jenis Employment',
                            ),
                        ).not.toBeDisabled();
                    },
                );

                fireEvent.change(
                    screen.getByLabelText(
                        'Jenis Employment',
                    ),
                    {
                        target: {
                            value: '01970000-0000-7000-8000-0000000009aa',
                        },
                    },
                );

                fireEvent.click(
                    screen.getByRole(
                        'button',
                        {
                            name: 'Tambah Pegawai',
                        },
                    ),
                );

                await waitFor(
                    () => {
                        expect(
                            screen.getByLabelText(
                                'Nama',
                            ),
                        ).toHaveValue(
                            '',
                        );
                    },
                );

                expect(
                    screen.getByLabelText(
                        'NIP',
                    ),
                ).toHaveValue(
                    '',
                );
            },
        );

        it(
            'shows a friendly message when the NIP is already taken',
            async () => {
                apiMockServer.use(
                    http.get(
                        '*/api/v1/hr/workspace/employees',
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
                        '*/api/v1/hr/employment-types',
                        () =>
                            HttpResponse.json(
                                {
                                    status: 'success',
                                    data: [
                                        {
                                            id: '01970000-0000-7000-8000-0000000009aa',
                                            code: 'TETAP',
                                            name: 'Tetap',
                                            description: null,
                                            is_active: true,
                                        },
                                    ],
                                },
                            ),
                    ),
                    http.post(
                        '*/api/v1/hr/workspace/employees',
                        () =>
                            HttpResponse.json(
                                {
                                    status: 'error',
                                    code: 'WORKSPACE_EMPLOYEE_PROVISIONING_CONFLICT',
                                    message:
                                        'NIP is already registered within this tenant.',
                                },
                                {
                                    status: 409,
                                },
                            ),
                    ),
                );

                renderWorkforcePage();

                await screen.findByText(
                    'Belum ada pegawai yang terlihat di workspace ini.',
                );

                fireEvent.change(
                    screen.getByLabelText(
                        'Nama',
                    ),
                    {
                        target: {
                            value: 'Dewi Lestari',
                        },
                    },
                );

                fireEvent.change(
                    screen.getByLabelText(
                        'NIP',
                    ),
                    {
                        target: {
                            value: 'NIP-DUPLICATE',
                        },
                    },
                );

                await waitFor(
                    () => {
                        expect(
                            screen.getByLabelText(
                                'Jenis Employment',
                            ),
                        ).not.toBeDisabled();
                    },
                );

                fireEvent.change(
                    screen.getByLabelText(
                        'Jenis Employment',
                    ),
                    {
                        target: {
                            value: '01970000-0000-7000-8000-0000000009aa',
                        },
                    },
                );

                fireEvent.click(
                    screen.getByRole(
                        'button',
                        {
                            name: 'Tambah Pegawai',
                        },
                    ),
                );

                expect(
                    await screen.findByRole(
                        'alert',
                    ),
                ).toHaveTextContent(
                    'NIP ini sudah dipakai pegawai lain di tenant Anda.',
                );
            },
        );

        it(
            'shows an empty state when the workspace has no visible employees',
            async () => {
                apiMockServer.use(
                    http.get(
                        '*/api/v1/hr/workspace/employees',
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
                );

                renderWorkforcePage();

                expect(
                    await screen.findByText(
                        'Belum ada pegawai yang terlihat di workspace ini.',
                    ),
                ).toBeInTheDocument();
            },
        );

        it(
            'shows an error state when the request fails',
            async () => {
                apiMockServer.use(
                    http.get(
                        '*/api/v1/hr/workspace/employees',
                        () =>
                            HttpResponse.json(
                                {
                                    status: 'error',
                                    code: 'AUTHORIZATION_DENIED',
                                    message: 'Denied.',
                                },
                                {
                                    status: 403,
                                },
                            ),
                    ),
                );

                renderWorkforcePage();

                expect(
                    await screen.findByRole(
                        'alert',
                    ),
                ).toHaveTextContent(
                    'Gagal memuat daftar pegawai',
                );
            },
        );

        it(
            'requests the clicked page from the numbered pagination control',
            async () => {
                const requestedPages: string[] = [];

                apiMockServer.use(
                    http.get(
                        '*/api/v1/hr/workspace/employees',
                        (
                            {
                                request,
                            },
                        ) => {
                            const url =
                                new URL(
                                    request.url,
                                );

                            const page =
                                url.searchParams.get(
                                    'page',
                                )
                                ?? '1';

                            requestedPages.push(
                                page,
                            );

                            return HttpResponse.json(
                                {
                                    status: 'success',
                                    data: [
                                        {
                                            id: '01970000-0000-7000-8000-0000000000ee',
                                            tenant_id:
                                                '01970000-0000-7000-8000-0000000000bb',
                                            membership_id:
                                                '01970000-0000-7000-8000-0000000000ff',
                                            nip: 'NIP-001',
                                            jabatan: 'GURU',
                                            nama: 'Budi Santoso',
                                            created_at:
                                                '2026-01-01T00:00:00Z',
                                        },
                                    ],
                                    meta: {
                                        current_page: Number(
                                            page,
                                        ),
                                        last_page: 5,
                                        per_page: 15,
                                        total: 75,
                                    },
                                },
                            );
                        },
                    ),
                );

                renderWorkforcePage();

                await screen.findByText(
                    'Budi Santoso',
                );

                expect(
                    screen.getByRole(
                        'button',
                        {
                            name: 'Halaman 1',
                        },
                    ),
                ).toHaveAttribute(
                    'aria-current',
                    'page',
                );

                fireEvent.click(
                    screen.getByRole(
                        'button',
                        {
                            name: 'Halaman 3',
                        },
                    ),
                );

                await waitFor(
                    () => {
                        expect(
                            screen.getByRole(
                                'button',
                                {
                                    name: 'Halaman 3',
                                },
                            ),
                        ).toHaveAttribute(
                            'aria-current',
                            'page',
                        );
                    },
                );

                expect(
                    requestedPages,
                ).toContain(
                    '3',
                );
            },
        );

        it(
            'creates a new employment type inline and auto-selects it for the employee form',
            async () => {
                let latestEmploymentTypes: unknown[] = [];

                apiMockServer.use(
                    http.get(
                        '*/api/v1/hr/workspace/employees',
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
                        '*/api/v1/hr/employment-types',
                        () =>
                            HttpResponse.json(
                                {
                                    status: 'success',
                                    data:
                                        latestEmploymentTypes,
                                },
                            ),
                    ),
                    http.post(
                        '*/api/v1/hr/employment-types',
                        async ({
                            request,
                        }) => {
                            const body =
                                await request.json() as Record<string, unknown>;

                            expect(
                                body,
                            ).toEqual(
                                {
                                    code: 'TETAP',
                                    name: 'Tetap',
                                    description: null,
                                },
                            );

                            const createdEmploymentType = {
                                id: '01970000-0000-7000-8000-0000000009aa',
                                code: 'TETAP',
                                name: 'Tetap',
                                description: null,
                                is_active: true,
                            };

                            latestEmploymentTypes =
                                [
                                    createdEmploymentType,
                                ];

                            return HttpResponse.json(
                                {
                                    status: 'success',
                                    data:
                                        createdEmploymentType,
                                },
                                {
                                    status: 201,
                                },
                            );
                        },
                    ),
                );

                renderWorkforcePage();

                await screen.findByText(
                    'Belum ada pegawai yang terlihat di workspace ini.',
                );

                fireEvent.click(
                    screen.getByRole(
                        'button',
                        {
                            name: '+ Jenis Baru',
                        },
                    ),
                );

                fireEvent.change(
                    screen.getByLabelText(
                        'Kode',
                    ),
                    {
                        target: {
                            value: 'TETAP',
                        },
                    },
                );

                fireEvent.change(
                    screen.getByLabelText(
                        'Nama',
                        {
                            selector: '#workforce-employment-type-name',
                        },
                    ),
                    {
                        target: {
                            value: 'Tetap',
                        },
                    },
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
                            screen.queryByRole(
                                'button',
                                {
                                    name: 'Simpan',
                                },
                            ),
                        ).not.toBeInTheDocument();
                    },
                );

                await waitFor(
                    () => {
                        expect(
                            screen.getByLabelText(
                                'Jenis Employment',
                            ),
                        ).toHaveValue(
                            '01970000-0000-7000-8000-0000000009aa',
                        );
                    },
                );
            },
        );

        it(
            'shows a friendly message when the employment type code is already taken',
            async () => {
                apiMockServer.use(
                    http.get(
                        '*/api/v1/hr/workspace/employees',
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
                        '*/api/v1/hr/employment-types',
                        () =>
                            HttpResponse.json(
                                {
                                    status: 'success',
                                    data: [],
                                },
                            ),
                    ),
                    http.post(
                        '*/api/v1/hr/employment-types',
                        () =>
                            HttpResponse.json(
                                {
                                    status: 'error',
                                    code: 'VALIDATION_FAILED',
                                    message: 'The code has already been taken.',
                                    errors: {
                                        code: [
                                            'The code has already been taken.',
                                        ],
                                    },
                                },
                                {
                                    status: 422,
                                },
                            ),
                    ),
                );

                renderWorkforcePage();

                await screen.findByText(
                    'Belum ada pegawai yang terlihat di workspace ini.',
                );

                fireEvent.click(
                    screen.getByRole(
                        'button',
                        {
                            name: '+ Jenis Baru',
                        },
                    ),
                );

                fireEvent.change(
                    screen.getByLabelText(
                        'Kode',
                    ),
                    {
                        target: {
                            value: 'TETAP',
                        },
                    },
                );

                fireEvent.change(
                    screen.getByLabelText(
                        'Nama',
                        {
                            selector: '#workforce-employment-type-name',
                        },
                    ),
                    {
                        target: {
                            value: 'Tetap',
                        },
                    },
                );

                fireEvent.click(
                    screen.getByRole(
                        'button',
                        {
                            name: 'Simpan',
                        },
                    ),
                );

                expect(
                    await screen.findByRole(
                        'alert',
                    ),
                ).toHaveTextContent(
                    'Kode ini sudah dipakai jenis employment lain.',
                );
            },
        );

        it(
            'creates a login account for an employee and shows the generated password once',
            async () => {
                apiMockServer.use(
                    http.get(
                        '*/api/v1/hr/workspace/employees',
                        () =>
                            HttpResponse.json(
                                {
                                    status: 'success',
                                    data: [
                                        {
                                            id: '01970000-0000-7000-8000-0000000060aa',
                                            tenant_id: '01970000-0000-7000-8000-0000000000bb',
                                            membership_id: '01970000-0000-7000-8000-0000000000ff',
                                            nip: 'NIP-060',
                                            jabatan: 'GURU',
                                            nama: 'Sari Wulandari',
                                            created_at: '2026-01-01T00:00:00Z',
                                        },
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
                        '*/api/v1/hr/employment-types',
                        () =>
                            HttpResponse.json(
                                {
                                    status: 'success',
                                    data: [],
                                },
                            ),
                    ),
                    http.post(
                        '*/api/v1/hr/workspace/employees/01970000-0000-7000-8000-0000000060aa/create-account',
                        async ({
                            request,
                        }) => {
                            const body =
                                await request.json() as Record<string, unknown>;

                            expect(
                                body,
                            ).toEqual(
                                {
                                    email: 'sari.wulandari@educore.test',
                                },
                            );

                            return HttpResponse.json(
                                {
                                    status: 'success',
                                    message: 'Login account created. The generated password is shown only once.',
                                    data: {
                                        user_id: '01970000-0000-7000-8000-0000000061aa',
                                        email: 'sari.wulandari@educore.test',
                                        generated_password: 'Xk9$mPq2#vT7wLzR',
                                    },
                                },
                                {
                                    status: 201,
                                },
                            );
                        },
                    ),
                );

                renderWorkforcePage();

                await screen.findByText(
                    'Sari Wulandari',
                );

                fireEvent.click(
                    screen.getByRole(
                        'button',
                        {
                            name: 'Buat Akun Login',
                        },
                    ),
                );

                fireEvent.change(
                    screen.getByLabelText(
                        'Email',
                    ),
                    {
                        target: {
                            value: 'sari.wulandari@educore.test',
                        },
                    },
                );

                fireEvent.click(
                    screen.getByRole(
                        'button',
                        {
                            name: 'Buat',
                        },
                    ),
                );

                expect(
                    await screen.findByText(
                        'Xk9$mPq2#vT7wLzR',
                    ),
                ).toBeInTheDocument();

                fireEvent.click(
                    screen.getByRole(
                        'button',
                        {
                            name: 'Sudah Dicatat, Tutup',
                        },
                    ),
                );

                await waitFor(
                    () => {
                        expect(
                            screen.getByRole(
                                'button',
                                {
                                    name: 'Buat Akun Login',
                                },
                            ),
                        ).toBeInTheDocument();
                    },
                );

                expect(
                    screen.queryByText(
                        'Xk9$mPq2#vT7wLzR',
                    ),
                ).not.toBeInTheDocument();
            },
        );

        it(
            'shows a friendly message when the employee already has a login account',
            async () => {
                apiMockServer.use(
                    http.get(
                        '*/api/v1/hr/workspace/employees',
                        () =>
                            HttpResponse.json(
                                {
                                    status: 'success',
                                    data: [
                                        {
                                            id: '01970000-0000-7000-8000-0000000062aa',
                                            tenant_id: '01970000-0000-7000-8000-0000000000bb',
                                            membership_id: '01970000-0000-7000-8000-0000000000ff',
                                            nip: 'NIP-062',
                                            jabatan: 'STAFF',
                                            nama: 'Dedi Hartono',
                                            created_at: '2026-01-01T00:00:00Z',
                                        },
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
                        '*/api/v1/hr/employment-types',
                        () =>
                            HttpResponse.json(
                                {
                                    status: 'success',
                                    data: [],
                                },
                            ),
                    ),
                    http.post(
                        '*/api/v1/hr/workspace/employees/01970000-0000-7000-8000-0000000062aa/create-account',
                        () =>
                            HttpResponse.json(
                                {
                                    status: 'error',
                                    code: 'EMPLOYEE_ACCOUNT_CONFLICT',
                                    message: 'This Employee already has a login account.',
                                },
                                {
                                    status: 409,
                                },
                            ),
                    ),
                );

                renderWorkforcePage();

                await screen.findByText(
                    'Dedi Hartono',
                );

                fireEvent.click(
                    screen.getByRole(
                        'button',
                        {
                            name: 'Buat Akun Login',
                        },
                    ),
                );

                fireEvent.change(
                    screen.getByLabelText(
                        'Email',
                    ),
                    {
                        target: {
                            value: 'dedi.hartono@educore.test',
                        },
                    },
                );

                fireEvent.click(
                    screen.getByRole(
                        'button',
                        {
                            name: 'Buat',
                        },
                    ),
                );

                expect(
                    await screen.findByRole(
                        'alert',
                    ),
                ).toHaveTextContent(
                    'Pegawai ini sudah punya akun login.',
                );
            },
        );
    },
);
