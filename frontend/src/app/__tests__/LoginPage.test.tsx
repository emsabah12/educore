import {
    fireEvent,
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
    LoginPage,
} from '@/app/LoginPage';
import {
    BrowserAuthProvider,
} from '@/app/auth/BrowserAuthProvider';
import type {
    BrowserAuthRuntime,
    BrowserAuthState,
    BrowserLoginRequest,
} from '@/platform/auth';

const tenantUuid =
    '018f3b6a-7c20-7cde-8def-1234567890ab';

interface RuntimeHarness {
    readonly runtime:
        BrowserAuthRuntime;

    readonly loginRequests:
        BrowserLoginRequest[];

    setState(
        nextState: BrowserAuthState,
    ): void;
}

function createRuntimeHarness(
    initialState: BrowserAuthState,
): RuntimeHarness {
    let state =
        initialState;

    const listeners =
        new Set<
            (
                state:
                    BrowserAuthState,
            ) => void
        >();

    const loginRequests:
        BrowserLoginRequest[] = [];

    function publish(): void {
        for (
            const listener
            of listeners
        ) {
            listener(
                state,
            );
        }
    }

    const runtime:
        BrowserAuthRuntime = {
        getState() {
            return state;
        },

        subscribe(
            listener,
        ) {
            listeners.add(
                listener,
            );

            return () => {
                listeners.delete(
                    listener,
                );
            };
        },

        async bootstrap() {
            return state;
        },

        async login(
            request,
        ) {
            loginRequests.push(
                request,
            );

            /*
             * Mirror the real runtime's synchronous
             * LOGIN_STARTED transition before transport
             * work begins.
             */
            state = {
                status:
                    'authenticating',
            };

            publish();

            return state;
        },

        async logout() {
            return state;
        },

        observeFailure() {
            return state;
        },
    };

    function setState(
        nextState: BrowserAuthState,
    ): void {
        state =
            nextState;

        publish();
    }

    return {
        runtime,
        loginRequests,
        setState,
    };
}

function renderLoginPage(
    harness: RuntimeHarness,
) {
    return render(
        <BrowserAuthProvider
            runtime={
                harness.runtime
            }
        >
            <LoginPage />
        </BrowserAuthProvider>,
    );
}

function fillValidForm(): void {
    fireEvent.change(
        screen.getByLabelText(
            'Email',
        ),
        {
            target: {
                value:
                    '  MEMBER@EXAMPLE.COM  ',
            },
        },
    );

    fireEvent.change(
        screen.getByLabelText(
            'Password',
        ),
        {
            target: {
                value:
                    '  secret value  ',
            },
        },
    );

    fireEvent.change(
        screen.getByLabelText(
            'Tenant UUID',
        ),
        {
            target: {
                value:
                    `  ${tenantUuid}  `,
            },
        },
    );
}

describe(
    'LoginPage',
    () => {
        it('dispatches only validated canonical login input through BrowserAuthRuntime', async () => {
            const harness =
                createRuntimeHarness({
                    status:
                        'anonymous',

                    failure:
                        null,
                });

            renderLoginPage(
                harness,
            );

            fillValidForm();

            fireEvent.click(
                screen.getByRole(
                    'button',
                    {
                        name:
                            'Masuk',
                    },
                ),
            );

            await waitFor(() => {
                expect(
                    harness.loginRequests,
                ).toHaveLength(
                    1,
                );
            });

            expect(
                harness.loginRequests[0],
            ).toEqual({
                email:
                    'member@example.com',

                password:
                    '  secret value  ',

                tenant_uuid:
                    tenantUuid,
            });
        });

        it('does not dispatch BrowserAuthRuntime login for locally invalid input', async () => {
            const harness =
                createRuntimeHarness({
                    status:
                        'anonymous',

                    failure:
                        null,
                });

            renderLoginPage(
                harness,
            );

            fireEvent.click(
                screen.getByRole(
                    'button',
                    {
                        name:
                            'Masuk',
                    },
                ),
            );

            expect(
                await screen.findByText(
                    'Email wajib diisi.',
                ),
            ).toBeInTheDocument();

            expect(
                harness.loginRequests,
            ).toHaveLength(
                0,
            );
        });

        it('disables authentication input while login is already authenticating', () => {
            const harness =
                createRuntimeHarness({
                    status:
                        'authenticating',
                });

            renderLoginPage(
                harness,
            );

            expect(
                screen.getByLabelText(
                    'Email',
                ),
            ).toBeDisabled();

            expect(
                screen.getByLabelText(
                    'Password',
                ),
            ).toBeDisabled();

            expect(
                screen.getByLabelText(
                    'Tenant UUID',
                ),
            ).toBeDisabled();

            expect(
                screen.getByRole(
                    'button',
                    {
                        name:
                            'Masuk',
                    },
                ),
            ).toBeDisabled();
        });

        it('prevents rapid duplicate authentication dispatch after the runtime leaves anonymous state', async () => {
            const harness =
                createRuntimeHarness({
                    status:
                        'anonymous',

                    failure:
                        null,
                });

            renderLoginPage(
                harness,
            );

            fillValidForm();

            const submit =
                screen.getByRole(
                    'button',
                    {
                        name:
                            'Masuk',
                    },
                );

            fireEvent.click(
                submit,
            );

            fireEvent.click(
                submit,
            );

            await waitFor(() => {
                expect(
                    harness.loginRequests,
                ).toHaveLength(
                    1,
                );
            });

            expect(
                harness.runtime
                    .getState()
                    .status,
            ).toBe(
                'authenticating',
            );
        });

         it('renders a safe invalid-credential message from canonical anonymous failure state', async () => {
            const harness =
                createRuntimeHarness({
                    status:
                        'anonymous',

                    failure: {
                        ok:
                            false,

                        kind:
                            'response',

                        status:
                            401,

                        error: {
                            status:
                                'error',

                            code:
                                'AUTHENTICATION_FAILED',

                            message:
                                'Sensitive backend authentication detail.',
                        },
                    },
                });

            renderLoginPage(
                harness,
            );

            expect(
                screen.getByText(
                    'Email, password, atau Tenant UUID tidak cocok.',
                ),
            ).toBeInTheDocument();

            expect(
                screen.queryByText(
                    'Sensitive backend authentication detail.',
                ),
            ).not.toBeInTheDocument();
        });

        it('renders canonical server validation as safe field presentation', () => {
            const harness =
                createRuntimeHarness({
                    status:
                        'anonymous',

                    failure: {
                        ok:
                            false,

                        kind:
                            'response',

                        status:
                            422,

                        error: {
                            status:
                                'error',

                            code:
                                'VALIDATION_FAILED',

                            message:
                                'The submitted data is invalid.',

                            errors: {
                                email: [
                                    'Sensitive raw validation detail.',
                                ],

                                tenant_uuid: [
                                    'Sensitive Tenant detail.',
                                ],
                            },
                        },
                    },
                });

            renderLoginPage(
                harness,
            );

            expect(
                screen.getByText(
                    'Periksa kembali data login yang ditandai.',
                ),
            ).toBeInTheDocument();

            expect(
                screen.getByText(
                    'Email tidak dapat diterima. Periksa kembali email Anda.',
                ),
            ).toBeInTheDocument();

            expect(
                screen.getByText(
                    'Tenant UUID tidak dapat diterima. Periksa kembali Tenant UUID Anda.',
                ),
            ).toBeInTheDocument();

            expect(
                screen.queryByText(
                    'Sensitive raw validation detail.',
                ),
            ).not.toBeInTheDocument();
        });

        it('dismisses the current server failure presentation when the user edits login input', async () => {
            const harness =
                createRuntimeHarness({
                    status:
                        'anonymous',

                    failure: {
                        ok:
                            false,

                        kind:
                            'response',

                        status:
                            401,

                        error: {
                            status:
                                'error',

                            code:
                                'AUTHENTICATION_FAILED',

                            message:
                                'Authentication failed.',
                        },
                    },
                });

            renderLoginPage(
                harness,
            );

            expect(
                screen.getByText(
                    'Email, password, atau Tenant UUID tidak cocok.',
                ),
            ).toBeInTheDocument();

            fireEvent.change(
                screen.getByLabelText(
                    'Email',
                ),
                {
                    target: {
                        value:
                            'member@example.com',
                    },
                },
            );

            await waitFor(() => {
                expect(
                    screen.queryByText(
                        'Email, password, atau Tenant UUID tidak cocok.',
                    ),
                ).not.toBeInTheDocument();
            });
        });
    },
);
