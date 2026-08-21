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

import {
    defineProtectedRoutePolicy,
} from '@/platform/routing';

vi.mock(
    '@/app/routing/useProtectedRouteAccess',
    () => ({
        useProtectedRouteAccess:
            vi.fn(),
    }),
);

import {
    ProtectedRouteBoundary,
} from '@/app/routing/ProtectedRouteBoundary';
import {
    useProtectedRouteAccess,
} from '@/app/routing/useProtectedRouteAccess';

const mockedUseProtectedRouteAccess =
    vi.mocked(
        useProtectedRouteAccess,
    );

const policy =
    defineProtectedRoutePolicy({
        routeId:
            'test.protected-route',

        contextRequirement:
            'tenant',

        authorizationScope:
            'tenant',

        requiredPermissions:
            null,
    });

function createTestRouter(
    initialEntry:
        string,
) {
    return createMemoryRouter(
        [
            {
                path:
                    '/protected',

                element: (
                    <ProtectedRouteBoundary
                        policy={policy}
                    >
                        <h1>
                            Protected content
                        </h1>
                    </ProtectedRouteBoundary>
                ),
            },
            {
                path:
                    '/login',

                element: (
                    <h1>
                        Login destination
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
    'ProtectedRouteBoundary',
    () => {
        beforeEach(
            () => {
                mockedUseProtectedRouteAccess
                    .mockReset();
            },
        );

        it('renders protected content only when route authority is allowed', () => {
            mockedUseProtectedRouteAccess
                .mockReturnValue({
                    status:
                        'allowed',
                });

            const router =
                createTestRouter(
                    '/protected',
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
                            'Protected content',
                    },
                ),
            ).toBeInTheDocument();
        });

        it('does not render protected content while route authority is pending', () => {
            mockedUseProtectedRouteAccess
                .mockReturnValue({
                    status:
                        'pending',

                    source:
                        'authentication',
                });

            const router =
                createTestRouter(
                    '/protected',
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
                            'Menyiapkan halaman',
                    },
                ),
            ).toBeInTheDocument();

            expect(
                screen.queryByRole(
                    'heading',
                    {
                        name:
                            'Protected content',
                    },
                ),
            ).not.toBeInTheDocument();
        });

        it('renders controlled Access Denied UX instead of protected content', () => {
            mockedUseProtectedRouteAccess
                .mockReturnValue({
                    status:
                        'denied',
                });

            const router =
                createTestRouter(
                    '/protected',
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
                            'Akses ditolak',
                    },
                ),
            ).toBeInTheDocument();

            expect(
                screen.queryByRole(
                    'heading',
                    {
                        name:
                            'Protected content',
                    },
                ),
            ).not.toBeInTheDocument();
        });

        it('navigates authoritative unauthenticated access to login with a validated internal return destination', async () => {
            mockedUseProtectedRouteAccess
                .mockReturnValue({
                    status:
                        'unauthenticated',
                });

            const router =
                createTestRouter(
                    '/protected?tab=active#details',
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
                        '/login',
                    );
                },
            );

            const parameters =
                new URLSearchParams(
                    router.state
                        .location
                        .search,
                );

            expect(
                parameters.get(
                    'returnTo',
                ),
            ).toBe(
                '/protected?tab=active#details',
            );

            expect(
                screen.getByRole(
                    'heading',
                    {
                        name:
                            'Login destination',
                    },
                ),
            ).toBeInTheDocument();

            expect(
                screen.queryByRole(
                    'heading',
                    {
                        name:
                            'Protected content',
                    },
                ),
            ).not.toBeInTheDocument();
        });
    },
);
