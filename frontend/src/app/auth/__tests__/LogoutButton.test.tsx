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
    vi,
} from 'vitest';

import {
    LogoutButton,
} from '@/app/auth/LogoutButton';
import {
    BrowserAuthProvider,
} from '@/app/auth/BrowserAuthProvider';
import {
    createBrowserAuthRuntime,
    type BrowserAuthOperations,
} from '@/platform/auth';

const membershipId =
    '018f3b6a-7c20-7cde-8def-1234567890ab';

const tenantId =
    '018f3b6a-7c20-7def-8abc-1234567890ab';

function createOperations(
    logout:
        BrowserAuthOperations['logout'],
): BrowserAuthOperations {
    return {
        async bootstrap() {
            return {
                ok:
                    true,

                status:
                    200,

                data: {
                    status:
                        'success',

                    data: {
                        user: {
                            id:
                                '018f3b6a-7c20-7000-8000-000000000001',

                            email:
                                'member@example.com',
                        },

                        person: {
                            id:
                                '018f3b6a-7c20-7000-8000-000000000002',

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
                                'educore-school',
                        },
                    },
                },
            };
        },

        async login() {
            throw new Error(
                'LogoutButton test must not perform login.',
            );
        },

        logout,
    };
}

async function renderAuthenticatedLogoutButton(
    logout:
        BrowserAuthOperations['logout'],
) {
    const runtime =
        createBrowserAuthRuntime(
            createOperations(
                logout,
            ),
        );

    await runtime.bootstrap();

    render(
        <BrowserAuthProvider
            runtime={
                runtime
            }
        >
            <LogoutButton />
        </BrowserAuthProvider>,
    );

    return runtime;
}

describe(
    'LogoutButton',
    () => {
        it('delegates logout exactly once to BrowserAuthRuntime', async () => {
            const logout =
                vi.fn(
                    async () => ({
                        ok:
                            true as const,

                        status:
                            200,

                        data: {
                            status:
                                'success' as const,

                            message:
                                'Logout completed successfully.' as const,
                        },
                    }),
                );

            const runtime =
                await renderAuthenticatedLogoutButton(
                    logout,
                );

            fireEvent.click(
                screen.getByRole(
                    'button',
                    {
                        name:
                            'Keluar',
                    },
                ),
            );

            await waitFor(() => {
                expect(
                    logout,
                ).toHaveBeenCalledTimes(
                    1,
                );
            });

            await waitFor(() => {
                expect(
                    runtime.getState(),
                ).toEqual({
                    status:
                        'anonymous',

                    failure:
                        null,
                });
            });

            expect(
                screen.queryByRole(
                    'button',
                    {
                        name:
                            'Keluar',
                    },
                ),
            ).not.toBeInTheDocument();
        });

        it('prevents duplicate logout dispatch while the first operation is live', async () => {
            let resolveLogout:
                (
                    value: {
                        ok: true;
                        status: 200;
                        data: {
                            status: 'success';
                            message: 'Logout completed successfully.';
                        };
                    },
                ) => void =
                    () => undefined;

            const logout =
                vi.fn(
                    () =>
                        new Promise<{
                            ok: true;
                            status: 200;
                            data: {
                                status: 'success';
                                message: 'Logout completed successfully.';
                            };
                        }>(
                            (resolve) => {
                                resolveLogout =
                                    resolve;
                            },
                        ),
                );

            await renderAuthenticatedLogoutButton(
                logout,
            );

            const button =
                screen.getByRole(
                    'button',
                    {
                        name:
                            'Keluar',
                    },
                );

            fireEvent.click(
                button,
            );

            await waitFor(() => {
                expect(
                    logout,
                ).toHaveBeenCalledTimes(
                    1,
                );
            });

            const loggingOutButton =
                screen.getByRole(
                    'button',
                    {
                        name:
                            'Keluar...',
                    },
                );

            expect(
                loggingOutButton,
            ).toBeDisabled();

            fireEvent.click(
                loggingOutButton,
            );

            expect(
                logout,
            ).toHaveBeenCalledTimes(
                1,
            );

            resolveLogout({
                ok:
                    true,

                status:
                    200,

                data: {
                    status:
                        'success',

                    message:
                        'Logout completed successfully.',
                },
            });

            await waitFor(() => {
                expect(
                    screen.queryByRole(
                        'button',
                        {
                            name:
                                'Keluar...',
                        },
                    ),
                ).not.toBeInTheDocument();
            });
        });

        it('does not render logout outside authoritative authentication lifecycle', () => {
            const runtime =
                createBrowserAuthRuntime(
                    createOperations(
                        async () => ({
                            ok:
                                true,

                            status:
                                200,

                            data: {
                                status:
                                    'success',

                                message:
                                    'Logout completed successfully.',
                            },
                        }),
                    ),
                );

            render(
                <BrowserAuthProvider
                    runtime={
                        runtime
                    }
                >
                    <LogoutButton />
                </BrowserAuthProvider>,
            );

            expect(
                screen.queryByRole(
                    'button',
                    {
                        name:
                            'Keluar',
                    },
                ),
            ).not.toBeInTheDocument();
        });
    },
);
