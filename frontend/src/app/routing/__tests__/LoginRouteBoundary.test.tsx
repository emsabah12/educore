import {
    render,
    screen,
    waitFor,
} from '@testing-library/react';
import type {
    BrowserAuthState,
} from '@/platform/auth';
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
    '@/app/LoginPage',
    () => ({
        LoginPage() {
            return (
                <h1>
                    Masuk ke EduCore
                </h1>
            );
        },
    }),
);

import {
    LoginRouteBoundary,
} from '@/app/routing/LoginRouteBoundary';

function createTestRouter(
    initialEntry:
        string,
) {
    return createMemoryRouter(
        [
            {
                path:
                    '/login',

                element: (
                    <LoginRouteBoundary />
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
    'LoginRouteBoundary',
    () => {
        beforeEach(
            () => {
                mocks
                    .authenticationStatus =
                    'anonymous';
            },
        );

        it('keeps authoritative anonymous users on the login route', () => {
            const router =
                createTestRouter(
                    '/login',
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
                            'Masuk ke EduCore',
                    },
                ),
            ).toBeInTheDocument();

            expect(
                router.state
                    .location
                    .pathname,
            ).toBe(
                '/login',
            );
        });

        it('does not redirect while authentication is unresolved', () => {
            mocks
                .authenticationStatus =
                'unknown';

            const router =
                createTestRouter(
                    '/login?returnTo=%2Facademic%2Fstudents',
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
                            'Masuk ke EduCore',
                    },
                ),
            ).toBeInTheDocument();

            expect(
                router.state
                    .location
                    .pathname,
            ).toBe(
                '/login',
            );
        });

        it('redirects authenticated users to a validated return destination', async () => {
            mocks
                .authenticationStatus =
                'authenticated';

            const router =
                createTestRouter(
                    '/login?returnTo=%2Facademic%2Fstudents',
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

        it('falls back to the canonical application entry for authenticated users without returnTo', async () => {
            mocks
                .authenticationStatus =
                'authenticated';

            const router =
                createTestRouter(
                    '/login',
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

        it('rejects an external return destination before authenticated navigation', async () => {
            mocks
                .authenticationStatus =
                'authenticated';

            const router =
                createTestRouter(
                    '/login?returnTo=https%3A%2F%2Fevil.example%2Fpath',
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

        it('rejects login as its own authenticated post-login destination', async () => {
            mocks
                .authenticationStatus =
                'authenticated';

            const router =
                createTestRouter(
                    '/login?returnTo=%2Flogin',
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

        it('leaves login when canonical Membership context selection is required', async () => {
            mocks
                .authenticationStatus =
                'membership-context-required';

            const router =
                createTestRouter(
                    '/login',
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
