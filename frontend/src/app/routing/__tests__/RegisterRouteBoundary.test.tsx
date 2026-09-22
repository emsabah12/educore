import {
    render,
    screen,
    waitFor,
} from '@testing-library/react';
import {
    createMemoryRouter,
} from 'react-router';
import {
    RouterProvider,
} from 'react-router/dom';
import {
    beforeEach,
    describe,
    expect,
    it,
    vi,
} from 'vitest';

import type {
    BrowserAuthState,
} from '@/platform/auth';

const mocks =
    vi.hoisted<{
        authenticationStatus:
            BrowserAuthState['status'];
    }>(
        () => ({
            authenticationStatus:
                'anonymous',
        }),
    );

vi.mock(
    '@/app/auth/BrowserAuthProvider',
    () => ({
        useBrowserAuthState:
            () => ({
                status:
                    mocks
                        .authenticationStatus,
            }),
    }),
);

vi.mock(
    '@/app/RegisterPage',
    () => ({
        RegisterPage() {
            return (
                <h1>
                    Daftarkan sekolah Anda
                </h1>
            );
        },
    }),
);

import {
    RegisterRouteBoundary,
} from '@/app/routing/RegisterRouteBoundary';

function createTestRouter(
    initialEntry:
        string,
) {
    return createMemoryRouter(
        [
            {
                path:
                    '/daftar',

                element: (
                    <RegisterRouteBoundary />
                ),
            },
            {
                path:
                    '/',

                element: (
                    <h1>
                        Application entry
                    </h1>
                ),
            },
            {
                path:
                    '/academic/students',

                element: (
                    <h1>
                        Academic students
                    </h1>
                ),
            },
        ],
        {
            initialEntries: [
                initialEntry,
            ],
        },
    );
}

describe(
    'RegisterRouteBoundary',
    () => {
        beforeEach(
            () => {
                mocks
                    .authenticationStatus =
                    'anonymous';
            },
        );

        it('keeps authoritative anonymous users on the register route', () => {
            const router =
                createTestRouter(
                    '/daftar',
                );

            render(
                <RouterProvider
                    router={router}
                />,
            );

            expect(
                screen.getByRole(
                    'heading',
                    {
                        name:
                            'Daftarkan sekolah Anda',
                    },
                ),
            ).toBeInTheDocument();

            expect(
                router.state
                    .location
                    .pathname,
            ).toBe(
                '/daftar',
            );
        });

        it('does not redirect while authentication is unresolved', () => {
            mocks
                .authenticationStatus =
                'unknown';

            const router =
                createTestRouter(
                    '/daftar',
                );

            render(
                <RouterProvider
                    router={router}
                />,
            );

            expect(
                screen.getByRole(
                    'heading',
                    {
                        name:
                            'Daftarkan sekolah Anda',
                    },
                ),
            ).toBeInTheDocument();

            expect(
                router.state
                    .location
                    .pathname,
            ).toBe(
                '/daftar',
            );
        });

        it('redirects an already-authenticated user away from the register route', async () => {
            mocks
                .authenticationStatus =
                'authenticated';

            const router =
                createTestRouter(
                    '/daftar',
                );

            render(
                <RouterProvider
                    router={router}
                />,
            );

            await waitFor(
                () => {
                    expect(
                        router.state
                            .location
                            .pathname,
                    ).toBe(
                        '/',
                    );
                },
            );

            expect(
                screen.getByRole(
                    'heading',
                    {
                        name:
                            'Application entry',
                    },
                ),
            ).toBeInTheDocument();
        });

        it('leaves registration for a freshly self-registered Tenant admin the exact same way login leaves credential entry', async () => {
            mocks
                .authenticationStatus =
                'identity-authenticated';

            const router =
                createTestRouter(
                    '/daftar',
                );

            render(
                <RouterProvider
                    router={router}
                />,
            );

            /*
             * §Bukti paling penting untuk boundary ini: begitu
             * status identity-authenticated -- persis hasil
             * BrowserAuthRuntime.register() -- pengguna TIDAK
             * boleh terus melihat form pendaftaran, sama seperti
             * LoginRouteBoundary sudah meninggalkan /login pada
             * status yang sama.
             */
            await waitFor(
                () => {
                    expect(
                        router.state
                            .location
                            .pathname,
                    ).toBe(
                        '/',
                    );
                },
            );

            expect(
                screen.queryByRole(
                    'heading',
                    {
                        name:
                            'Daftarkan sekolah Anda',
                    },
                ),
            ).not.toBeInTheDocument();

            expect(
                screen.getByRole(
                    'heading',
                    {
                        name:
                            'Application entry',
                    },
                ),
            ).toBeInTheDocument();
        });

        it('redirects to a validated returnTo destination after registration', async () => {
            mocks
                .authenticationStatus =
                'identity-authenticated';

            const router =
                createTestRouter(
                    '/daftar?returnTo=%2Facademic%2Fstudents',
                );

            render(
                <RouterProvider
                    router={router}
                />,
            );

            await waitFor(
                () => {
                    expect(
                        router.state
                            .location
                            .pathname,
                    ).toBe(
                        '/academic/students',
                    );
                },
            );

            expect(
                screen.getByRole(
                    'heading',
                    {
                        name:
                            'Academic students',
                    },
                ),
            ).toBeInTheDocument();
        });

        it('rejects an external return destination before authenticated navigation', async () => {
            mocks
                .authenticationStatus =
                'authenticated';

            const router =
                createTestRouter(
                    '/daftar?returnTo=https%3A%2F%2Fevil.example%2Fpath',
                );

            render(
                <RouterProvider
                    router={router}
                />,
            );

            await waitFor(
                () => {
                    expect(
                        router.state
                            .location
                            .pathname,
                    ).toBe(
                        '/',
                    );
                },
            );

            expect(
                router.state
                    .location
                    .pathname,
            ).not.toContain(
                'evil.example',
            );
        });

        it('leaves registration when canonical Membership context selection is required', async () => {
            mocks
                .authenticationStatus =
                'membership-context-required';

            const router =
                createTestRouter(
                    '/daftar',
                );

            render(
                <RouterProvider
                    router={router}
                />,
            );

            await waitFor(
                () => {
                    expect(
                        router.state
                            .location
                            .pathname,
                    ).toBe(
                        '/',
                    );
                },
            );

            expect(
                screen.getByRole(
                    'heading',
                    {
                        name:
                            'Application entry',
                    },
                ),
            ).toBeInTheDocument();
        });
    },
);
