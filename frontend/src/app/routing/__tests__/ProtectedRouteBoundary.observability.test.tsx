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
    ObservabilityContextProvider,
} from '@/app/observability/ObservabilityContextProvider';
import type {
    ObservabilityPort,
} from '@/platform/observability/port';
import {
    defineProtectedRoutePolicy,
} from '@/platform/routing';

interface RecoveryDependencies {
    readonly reportFailure:
        (
            error:
                unknown,
        ) => void;
}

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
                    async (
                        _source:
                            unknown,
                        _dependencies:
                            RecoveryDependencies,
                    ): Promise<void> =>
                        undefined,
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
    observability:
        ObservabilityPort,
) {
    return createMemoryRouter(
        [
            {
                path:
                    '/protected',

                element: (
                    <ObservabilityContextProvider
                        observability={
                            observability
                        }
                    >
                        <ProtectedRouteBoundary
                            policy={
                                policy
                            }
                        >
                            <h1>
                                Protected content
                            </h1>
                        </ProtectedRouteBoundary>
                    </ObservabilityContextProvider>
                ),
            },
        ],
        {
            initialEntries: [
                '/protected',
            ],
        },
    );
}

describe(
    'ProtectedRouteBoundary observability',
    () => {
        beforeEach(
            () => {
                mockedUseProtectedRouteAccess
                    .mockReset();

                recoveryMocks
                    .recover
                    .mockClear();
            },
        );

        it('reports exceptional recovery failures through observability without the legacy console reporter', async () => {
            mockedUseProtectedRouteAccess
                .mockReturnValue({
                    status:
                        'unavailable',

                    source:
                        'workspace',

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

            const observability:
                ObservabilityPort = {
                    captureEvent:
                        vi.fn(),

                    captureException:
                        vi.fn(),
                };

            const consoleError =
                vi.spyOn(
                    console,
                    'error',
                )
                    .mockImplementation(
                        () => undefined,
                    );

            const router =
                createTestRouter(
                    observability,
                );

            render(
                <RouterProvider
                    router={
                        router
                    }
                />,
            );

            fireEvent.click(
                screen.getByRole(
                    'button',
                    {
                        name:
                            'Coba lagi',
                    },
                ),
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

            const recoveryCall =
                recoveryMocks
                    .recover
                    .mock
                    .calls[0];

            if (
                recoveryCall
                    === undefined
            ) {
                throw new Error(
                    'Expected protected route recovery invocation.',
                );
            }

            const [
                ,
                dependencies,
            ] = recoveryCall;

            const recoveryFailure =
                Object.assign(
                    new Error(
                        'Sensitive recovery failure',
                    ),
                    {
                        authorization:
                            'Bearer secret-token',

                        cookie:
                            'browser_session=secret',

                        workspacePayload: {
                            id:
                                'workspace-secret-id',
                        },
                    },
                );

            dependencies
                .reportFailure(
                    recoveryFailure,
                );

            expect(
                observability
                    .captureException,
            ).toHaveBeenCalledTimes(
                1,
            );

            expect(
                observability
                    .captureException,
            ).toHaveBeenCalledWith(
                'protected_route_recovery_failed',
                recoveryFailure,
                {
                    module:
                        'routing',

                    routeId:
                        'test.protected-route',
                },
            );

            expect(
                observability
                    .captureEvent,
            ).not.toHaveBeenCalled();

            expect(
                consoleError.mock.calls
                    .some(
                        (
                            [
                                firstArgument,
                            ],
                        ) =>
                            firstArgument
                                === 'EduCore protected route recovery failed.',
                    ),
            ).toBe(
                false,
            );

            consoleError
                .mockRestore();
        });
    },
);
