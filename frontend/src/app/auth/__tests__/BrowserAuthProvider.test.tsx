import {
    StrictMode,
} from 'react';
import {
    act,
    render,
    screen,
    waitFor,
} from '@testing-library/react';
import {
    describe,
    expect,
    it,
} from 'vitest';

import {
    BrowserAuthProvider,
    useBrowserAuthState,
} from '@/app/auth/BrowserAuthProvider';
import type {
    BrowserApiFailure,
    BrowserApiResult,
} from '@/platform/api';
import {
    createBrowserAuthRuntime,
    type AuthenticatedBootstrapSuccess,
    type BrowserAuthOperations,
} from '@/platform/auth';

const membershipId =
    '018f3b6a-7c20-7abc-8def-1234567890ab';

const tenantId =
    '018f3b6a-7c20-7bcd-8def-1234567890ab';

const bootstrapSuccess:
    BrowserApiResult<
        AuthenticatedBootstrapSuccess
    > = {
        ok: true,
        status: 200,
        data: {
            status:
                'success',
            data: {
                user: {
                    id:
                        '018f3b6a-7c20-7cde-8def-1234567890ab',
                    email:
                        'member@example.com',
                },

                person: {
                    id:
                        '018f3b6a-7c20-7def-8abc-1234567890ab',
                    name:
                        'EduCore Member',
                },

                membership: {
                    id:
                        membershipId,
                    status:
                        'ACTIVE',
                },

                tenant: {
                    id:
                        tenantId,
                    name:
                        'EduCore School',
                    subdomain:
                        'school',
                },
            },
        },
    };

const sessionRequiredFailure:
    BrowserApiFailure = {
        ok: false,
        kind: 'response',
        status: 401,
        error: {
            status:
                'error',
            code:
                'BROWSER_SESSION_AUTHENTICATION_REQUIRED',
            message:
                'Authenticated browser session is required.',
        },
    };

const abortedFailure:
    BrowserApiFailure = {
        ok: false,
        kind:
            'aborted',
        cause:
            new Error(
                'Bootstrap cancelled',
            ),
    };

function createOperations(
    bootstrap:
        BrowserAuthOperations['bootstrap'],
): BrowserAuthOperations {
    return {
        bootstrap,

        async login() {
            throw new Error(
                'Unexpected login operation.',
            );
        },

        async logout() {
            throw new Error(
                'Unexpected logout operation.',
            );
        },
    };
}

function AuthStateProbe() {
    const state =
        useBrowserAuthState();

    return (
        <output
            data-testid="auth-state"
        >
            {state.status}
        </output>
    );
}

describe(
    'BrowserAuthProvider',
    () => {
        it('bootstraps initial authentication truth after mount', async () => {
            let bootstrapCalls =
                0;

            const runtime =
                createBrowserAuthRuntime(
                    createOperations(
                        async () => {
                            bootstrapCalls +=
                                1;

                            return sessionRequiredFailure;
                        },
                    ),
                );

            render(
                <BrowserAuthProvider
                    runtime={runtime}
                >
                    <AuthStateProbe />
                </BrowserAuthProvider>,
            );

            expect(
                await screen.findByText(
                    'anonymous',
                ),
            ).toBeInTheDocument();

            expect(
                bootstrapCalls,
            ).toBe(1);
        });

        it('re-renders subscribed consumers when authentication state changes', async () => {
            const runtime =
                createBrowserAuthRuntime(
                    createOperations(
                        async () =>
                            bootstrapSuccess,
                    ),
                );

            render(
                <BrowserAuthProvider
                    runtime={runtime}
                >
                    <AuthStateProbe />
                </BrowserAuthProvider>,
            );

            expect(
                await screen.findByText(
                    'authenticated',
                ),
            ).toBeInTheDocument();

            act(() => {
                runtime.observeFailure(
                    sessionRequiredFailure,
                );
            });

            expect(
                screen.getByText(
                    'anonymous',
                ),
            ).toBeInTheDocument();
        });

        it('remains deterministic across StrictMode Effect setup and cleanup', async () => {
            let bootstrapCalls =
                0;

            const runtime =
                createBrowserAuthRuntime(
                    createOperations(
                        async (
                            options,
                        ) => {
                            bootstrapCalls +=
                                1;

                            if (
                                bootstrapCalls
                                    !== 1
                            ) {
                                return bootstrapSuccess;
                            }

                            const signal =
                                options?.signal;

                            if (
                                signal
                                    === undefined
                            ) {
                                throw new Error(
                                    'Expected bootstrap AbortSignal.',
                                );
                            }

                            return new Promise(
                                (
                                    resolve,
                                ) => {
                                    const resolveAbort =
                                        () => {
                                            window.setTimeout(
                                                () => {
                                                    resolve(
                                                        abortedFailure,
                                                    );
                                                },
                                                10,
                                            );
                                        };

                                    if (
                                        signal.aborted
                                    ) {
                                        resolveAbort();

                                        return;
                                    }

                                    signal.addEventListener(
                                        'abort',
                                        resolveAbort,
                                        {
                                            once:
                                                true,
                                        },
                                    );
                                },
                            );
                        },
                    ),
                );

            render(
                <StrictMode>
                    <BrowserAuthProvider
                        runtime={runtime}
                    >
                        <AuthStateProbe />
                    </BrowserAuthProvider>
                </StrictMode>,
            );

            expect(
                await screen.findByText(
                    'authenticated',
                ),
            ).toBeInTheDocument();

            await waitFor(() => {
                expect(
                    bootstrapCalls,
                ).toBe(2);
            });

            await act(
                async () => {
                    await new Promise(
                        (
                            resolve,
                        ) => {
                            window.setTimeout(
                                resolve,
                                20,
                            );
                        },
                    );
                },
            );

            expect(
                runtime.getState().status,
            ).toBe(
                'authenticated',
            );

            expect(
                screen.getByText(
                    'authenticated',
                ),
            ).toBeInTheDocument();
        });
    },
);
