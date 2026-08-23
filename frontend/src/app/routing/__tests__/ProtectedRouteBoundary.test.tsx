import {
    fireEvent,
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

const recoveryMocks =
    vi.hoisted(
        () => ({
            authentication: {
                bootstrap:
                    vi.fn(),
            },

            authenticationState: {
                status:
                    'authenticated' as const,
            },

            membership: {
                bootstrap:
                    vi.fn(),
            },

            workspace: {
                bootstrap:
                    vi.fn(),
            },

            capabilities: {
                refresh:
                    vi.fn(),
            },

            recover:
                vi.fn(
                    async () => undefined,
                ),
        }),
    );

vi.mock(
    '@/app/auth/BrowserAuthProvider',
    () => ({
        useBrowserAuthRuntime:
            () =>
                recoveryMocks
                    .authentication,

        useBrowserAuthState:
            () =>
                recoveryMocks
                    .authenticationState,
    }),
);

vi.mock(
    '@/app/membership/MembershipContextProvider',
    () => ({
        useMembershipContextRuntime:
            () =>
                recoveryMocks
                    .membership,
    }),
);

vi.mock(
    '@/app/workspace/WorkspaceContextProvider',
    () => ({
        useWorkspaceContextRuntime:
            () =>
                recoveryMocks
                    .workspace,
    }),
);

vi.mock(
    '@/app/authorization/CapabilityContextProvider',
    () => ({
        useCapabilityRuntime:
            () =>
                recoveryMocks
                    .capabilities,
    }),
);

vi.mock(
    '@/app/routing/protected-route-recovery',
    () => ({
        recoverProtectedRouteUnavailableSource:
            recoveryMocks
                .recover,
    }),
);

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

                recoveryMocks
                    .recover
                    .mockClear();

                recoveryMocks
                    .authentication
                    .bootstrap
                    .mockClear();

                recoveryMocks
                    .membership
                    .bootstrap
                    .mockClear();

                recoveryMocks
                    .workspace
                    .bootstrap
                    .mockClear();

                recoveryMocks
                    .capabilities
                    .refresh
                    .mockClear();
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

        it.each([
            'authentication',
            'membership',
            'workspace',
            'authorization',
        ] as const)(
            'wires %s unavailability to controlled provider-owned recovery',
            async (source) => {
                mockedUseProtectedRouteAccess
                    .mockReturnValue({
                        status:
                            'unavailable',

                        source,

                        failure: {
                            ok:
                                false,

                            kind:
                                'network',

                            cause:
                                new Error(
                                    'sensitive transport detail',
                                ),
                        },
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

                const retryButton =
                    screen.getByRole(
                        'button',
                        {
                            name:
                                'Coba lagi',
                        },
                    );

                fireEvent.click(
                    retryButton,
                );

                await waitFor(
                    () => {
                        expect(
                            recoveryMocks
                                .recover,
                        ).toHaveBeenCalledTimes(
                            1,
                        );
                    },
                );

                expect(
                    recoveryMocks
                        .recover,
                ).toHaveBeenCalledWith(
                    source,
                    {
                        authenticationStatus:
                            'authenticated',

                        authentication:
                            recoveryMocks
                                .authentication,

                        membership:
                            recoveryMocks
                                .membership,

                        workspace:
                            recoveryMocks
                                .workspace,

                        capabilities:
                            recoveryMocks
                                .capabilities,

                        reportFailure:
                            expect.any(
                                Function,
                            ),
                    },
                );

                expect(
                    screen.queryByText(
                        'sensitive transport detail',
                    ),
                ).not.toBeInTheDocument();
            },
        );

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
