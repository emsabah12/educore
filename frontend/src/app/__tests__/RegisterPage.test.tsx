import {
    fireEvent,
    render,
    screen,
    waitFor,
} from '@testing-library/react';
import {
    MemoryRouter,
} from 'react-router';
import {
    describe,
    expect,
    it,
} from 'vitest';

import {
    RegisterPage,
} from '@/app/RegisterPage';
import {
    BrowserAuthProvider,
} from '@/app/auth/BrowserAuthProvider';
import type {
    BrowserAuthRuntime,
    BrowserAuthState,
    TenantRegistrationRequest,
} from '@/platform/auth';

interface RuntimeHarness {
    readonly runtime:
        BrowserAuthRuntime;

    readonly registerRequests:
        TenantRegistrationRequest[];

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

    const registerRequests:
        TenantRegistrationRequest[] = [];

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

        async login() {
            return state;
        },

        async register(
            request,
        ) {
            registerRequests.push(
                request,
            );

            /*
             * Mirror the real runtime's synchronous
             * LOGIN_STARTED transition before transport
             * work begins -- register() reuses this exact
             * action, see BrowserAuthRuntime.register().
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
        registerRequests,
        setState,
    };
}

function renderRegisterPage(
    harness: RuntimeHarness,
) {
    return render(
        <MemoryRouter>
            <BrowserAuthProvider
                runtime={
                    harness.runtime
                }
            >
                <RegisterPage />
            </BrowserAuthProvider>
        </MemoryRouter>,
    );
}

function fillValidForm(): void {
    fireEvent.change(
        screen.getByLabelText(
            'Nama sekolah/institusi',
        ),
        {
            target: {
                value:
                    'SMA Negeri Uji Coba',
            },
        },
    );

    fireEvent.change(
        screen.getByLabelText(
            'Subdomain',
        ),
        {
            target: {
                value:
                    '  SMA-Uji-Coba  ',
            },
        },
    );

    fireEvent.change(
        screen.getByLabelText(
            'Nama Anda',
        ),
        {
            target: {
                value:
                    'Kepala Sekolah Baru',
            },
        },
    );

    fireEvent.change(
        screen.getByLabelText(
            'Email Anda',
        ),
        {
            target: {
                value:
                    '  ADMIN-BARU@EXAMPLE.COM  ',
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
}

describe(
    'RegisterPage',
    () => {
        it('describes self-service Tenant registration', () => {
            const harness =
                createRuntimeHarness({
                    status:
                        'anonymous',

                    failure:
                        null,
                });

            renderRegisterPage(
                harness,
            );

            expect(
                screen.getByText(
                    /Buat ruang kerja EduCore baru/,
                ),
            ).toBeInTheDocument();
        });

        it('dispatches only validated, normalized Tenant registration input through BrowserAuthRuntime', async () => {
            const harness =
                createRuntimeHarness({
                    status:
                        'anonymous',

                    failure:
                        null,
                });

            renderRegisterPage(
                harness,
            );

            fillValidForm();

            fireEvent.click(
                screen.getByRole(
                    'button',
                    {
                        name:
                            'Daftar',
                    },
                ),
            );

            await waitFor(() => {
                expect(
                    harness.registerRequests,
                ).toHaveLength(
                    1,
                );
            });

            expect(
                harness.registerRequests[0],
            ).toEqual({
                name:
                    'SMA Negeri Uji Coba',

                subdomain:
                    'sma-uji-coba',

                admin_name:
                    'Kepala Sekolah Baru',

                admin_email:
                    'admin-baru@example.com',

                admin_password:
                    '  secret value  ',
            });
        });

        it('does not dispatch BrowserAuthRuntime register for locally invalid input', async () => {
            const harness =
                createRuntimeHarness({
                    status:
                        'anonymous',

                    failure:
                        null,
                });

            renderRegisterPage(
                harness,
            );

            fireEvent.click(
                screen.getByRole(
                    'button',
                    {
                        name:
                            'Daftar',
                    },
                ),
            );

            expect(
                await screen.findByText(
                    'Subdomain wajib diisi.',
                ),
            ).toBeInTheDocument();

            expect(
                harness.registerRequests,
            ).toHaveLength(
                0,
            );
        });

        it('disables registration input while registration is already authenticating', () => {
            const harness =
                createRuntimeHarness({
                    status:
                        'authenticating',
                });

            renderRegisterPage(
                harness,
            );

            expect(
                screen.getByLabelText(
                    'Nama sekolah/institusi',
                ),
            ).toBeDisabled();

            expect(
                screen.getByLabelText(
                    'Subdomain',
                ),
            ).toBeDisabled();

            expect(
                screen.getByRole(
                    'button',
                    {
                        name:
                            'Daftar',
                    },
                ),
            ).toBeDisabled();
        });

        it('prevents rapid duplicate registration dispatch after the runtime leaves anonymous state', async () => {
            const harness =
                createRuntimeHarness({
                    status:
                        'anonymous',

                    failure:
                        null,
                });

            renderRegisterPage(
                harness,
            );

            fillValidForm();

            const submit =
                screen.getByRole(
                    'button',
                    {
                        name:
                            'Daftar',
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
                    harness.registerRequests,
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

        it('renders a registration-specific message when the Tenant was created but the Browser session could not be established', () => {
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
                            503,

                        error: {
                            status:
                                'error',

                            code:
                                'BROWSER_SESSION_UNAVAILABLE',

                            message:
                                'Internal session custody detail.',
                        },
                    },
                });

            renderRegisterPage(
                harness,
            );

            expect(
                screen.getByText(
                    'Sekolah Anda berhasil didaftarkan, tapi kami tidak dapat langsung memasukkan Anda. Silakan masuk secara manual.',
                ),
            ).toBeInTheDocument();

            expect(
                screen.queryByText(
                    'Internal session custody detail.',
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
                                subdomain: [
                                    'Sensitive raw validation detail.',
                                ],
                            },
                        },
                    },
                });

            renderRegisterPage(
                harness,
            );

            expect(
                screen.getByText(
                    'Periksa kembali data yang ditandai.',
                ),
            ).toBeInTheDocument();

            expect(
                screen.getByText(
                    'Subdomain tidak dapat diterima. Kemungkinan sudah dipakai institusi lain.',
                ),
            ).toBeInTheDocument();

            expect(
                screen.queryByText(
                    'Sensitive raw validation detail.',
                ),
            ).not.toBeInTheDocument();
        });

        it('dismisses the current server failure presentation when the user edits registration input', async () => {
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
                                subdomain: [
                                    'Already registered.',
                                ],
                            },
                        },
                    },
                });

            renderRegisterPage(
                harness,
            );

            expect(
                screen.getByText(
                    'Periksa kembali data yang ditandai.',
                ),
            ).toBeInTheDocument();

            fireEvent.change(
                screen.getByLabelText(
                    'Subdomain',
                ),
                {
                    target: {
                        value:
                            'sekolah-lain',
                    },
                },
            );

            await waitFor(() => {
                expect(
                    screen.queryByText(
                        'Periksa kembali data yang ditandai.',
                    ),
                ).not.toBeInTheDocument();
            });
        });

        it('links back to the login page', () => {
            const harness =
                createRuntimeHarness({
                    status:
                        'anonymous',

                    failure:
                        null,
                });

            renderRegisterPage(
                harness,
            );

            expect(
                screen.getByRole(
                    'link',
                    {
                        name:
                            'Masuk',
                    },
                ),
            ).toHaveAttribute(
                'href',
                '/login',
            );
        });
    },
);
