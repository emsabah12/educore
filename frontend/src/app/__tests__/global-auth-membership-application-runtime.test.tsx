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
    BrowserAuthProvider,
    useBrowserAuthRuntime,
    useBrowserAuthState,
} from '@/app/auth/BrowserAuthProvider';

import {
    LoginForm,
} from '@/app/auth/LoginForm';

import {
    MembershipContextProvider,
    useMembershipContextState,
} from '@/app/membership/MembershipContextProvider';

import type {
    BrowserApiFailure,
    BrowserApiResult,
} from '@/platform/api';

import {
    createBrowserAuthRuntime,
    type AuthenticatedBootstrapSuccess,
    type BrowserAuthOperations,
    type BrowserLoginSuccess,
} from '@/platform/auth';

import {
    createMembershipContextRuntime,
    type BrowserMembershipSwitchSuccess,
    type MembershipContextOperations,
    type MembershipListSuccess,
    type MembershipSummary,
} from '@/platform/membership';

const userId =
    '018f3b6a-7c20-7aaa-8def-1234567890ab';

const personId =
    '018f3b6a-7c20-7aab-8def-1234567890ab';

const membershipAId =
    '018f3b6a-7c20-7abc-8def-1234567890ab';

const membershipBId =
    '018f3b6a-7c20-7bcd-8def-1234567890ab';

const tenantAId =
    '018f3b6a-7c20-7cde-8def-1234567890ab';

const tenantBId =
    '018f3b6a-7c20-7def-8abc-1234567890ab';

const membershipA:
    MembershipSummary = {
        membership_id:
            membershipAId,

        membership_status:
            'ACTIVE',

        tenant_id:
            tenantAId,

        tenant_name:
            'EduCore School A',

        tenant_subdomain:
            'school-a',
    };

const membershipB:
    MembershipSummary = {
        membership_id:
            membershipBId,

        membership_status:
            'ACTIVE',

        tenant_id:
            tenantBId,

        tenant_name:
            'EduCore School B',

        tenant_subdomain:
            'school-b',
    };

const anonymousSessionFailure:
    BrowserApiFailure = {
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
                'BROWSER_SESSION_AUTHENTICATION_REQUIRED',

            message:
                'Authenticated Browser Session is required.',
        },
    };

const identityLoginSuccess:
    BrowserApiResult<
        BrowserLoginSuccess
    > = {
        ok:
            true,

        status:
            200,

        data: {
            status:
                'success',

            data: {
                context_type:
                    'identity',

                user: {
                    id:
                        userId,

                    name:
                        'EduCore User',

                    email:
                        'user@example.test',

                    username:
                        'user',
                },

                platform: {
                    is_superadmin:
                        false,
                },
            },
        },
    };

const authenticatedA:
    BrowserApiResult<
        AuthenticatedBootstrapSuccess
    > = {
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
                        userId,

                    email:
                        'user@example.test',
                },

                person: {
                    id:
                        personId,

                    name:
                        'EduCore User',
                },

                membership: {
                    id:
                        membershipAId,

                    status:
                        'ACTIVE',
                },

                tenant: {
                    id:
                        tenantAId,

                    name:
                        'EduCore School A',

                    subdomain:
                        'school-a',
                },
            },
        },
    };

const switchSuccessA:
    BrowserApiResult<
        BrowserMembershipSwitchSuccess
    > = {
        ok:
            true,

        status:
            200,

        data: {
            status:
                'success',

            data: {
                membership_id:
                    membershipAId,

                tenant_id:
                    tenantAId,

                tenant_name:
                    'EduCore School A',
            },
        },
    };

function membershipDiscovery(
    memberships:
        readonly MembershipSummary[],
): BrowserApiResult<
    MembershipListSuccess
> {
    return {
        ok:
            true,

        status:
            200,

        data: {
            status:
                'success',

            data:
                [...memberships],
        },
    };
}

function ApplicationProbe() {
    const authentication =
        useBrowserAuthState();

    const membership =
        useMembershipContextState();

    const authenticationRuntime =
        useBrowserAuthRuntime();

    return (
        <>
            <LoginForm
                onValidatedSubmit={
                    async (
                        request,
                    ) => {
                        await authenticationRuntime
                            .login(
                                request,
                            );
                    }
                }
            />

            <output
                data-testid="authentication-status"
            >
                {authentication.status}
            </output>

            <output
                data-testid="membership-status"
            >
                {membership.status}
            </output>
        </>
    );
}

interface ApplicationHarness {
    readonly login:
        ReturnType<
            typeof vi.fn
        >;

    readonly discover:
        ReturnType<
            typeof vi.fn
        >;

    readonly switchMembership:
        ReturnType<
            typeof vi.fn
        >;

    readonly bootstrapAuthentication:
        ReturnType<
            typeof vi.fn
        >;
}

function renderApplication(
    memberships:
        readonly MembershipSummary[],
): ApplicationHarness {
    const bootstrapAuthentication =
        vi.fn(
            async (
                options?: {
                    membershipId?:
                        string;

                    signal?:
                        AbortSignal;
                },
            ) => {
                if (
                    options?.membershipId
                        === membershipAId
                ) {
                    return authenticatedA;
                }

                return anonymousSessionFailure;
            },
        );

    const login =
        vi.fn(
            async () =>
                identityLoginSuccess,
        );

    const authenticationOperations:
        BrowserAuthOperations = {
        bootstrap:
            bootstrapAuthentication,

        login,

        async logout() {
            return {
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
            };
        },
    };

    const authentication =
        createBrowserAuthRuntime(
            authenticationOperations,
        );

    const discover =
        vi.fn(
            async () =>
                membershipDiscovery(
                    memberships,
                ),
        );

    const switchMembership =
        vi.fn(
            async (
                membershipId:
                    string,
            ) => {
                if (
                    membershipId
                        !== membershipAId
                ) {
                    throw new Error(
                        'Unexpected Membership target.',
                    );
                }

                return switchSuccessA;
            },
        );

    const membershipOperations:
        MembershipContextOperations = {
        discover,

        switchMembership,
    };

    const membership =
        createMembershipContextRuntime(
            membershipOperations,
            authentication,
        );

    render(
        <BrowserAuthProvider
            runtime={
                authentication
            }
        >
            <MembershipContextProvider
                runtime={
                    membership
                }
            >
                <ApplicationProbe />
            </MembershipContextProvider>
        </BrowserAuthProvider>,
    );

    return {
        login,
        discover,
        switchMembership,
        bootstrapAuthentication,
    };
}

async function submitLogin(): Promise<void> {
    await waitFor(() => {
        expect(
            screen.getByTestId(
                'authentication-status',
            ),
        ).toHaveTextContent(
            'anonymous',
        );
    });

    fireEvent.change(
        screen.getByLabelText(
            'Email atau username',
        ),
        {
            target: {
                value:
                    'user',
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
                    'correct-horse-battery-staple',
            },
        },
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
}

describe(
    'global authentication to Membership application lifecycle',
    () => {
        it('keeps a User with zero Memberships globally authenticated without Tenant context', async () => {
            const harness =
                renderApplication(
                    [],
                );

            await submitLogin();

            await waitFor(() => {
                expect(
                    screen.getByTestId(
                        'authentication-status',
                    ),
                ).toHaveTextContent(
                    'identity-authenticated',
                );

                expect(
                    screen.getByTestId(
                        'membership-status',
                    ),
                ).toHaveTextContent(
                    'empty',
                );
            });

            expect(
                harness.login,
            ).toHaveBeenCalledTimes(
                1,
            );

            expect(
                harness.discover,
            ).toHaveBeenCalledTimes(
                1,
            );

            expect(
                harness.switchMembership,
            ).not.toHaveBeenCalled();
        });

        it('auto-selects exactly one Membership through switch and canonical Tenant verification', async () => {
            const harness =
                renderApplication([
                    membershipA,
                ]);

            await submitLogin();

            await waitFor(() => {
                expect(
                    screen.getByTestId(
                        'authentication-status',
                    ),
                ).toHaveTextContent(
                    'authenticated',
                );

                expect(
                    screen.getByTestId(
                        'membership-status',
                    ),
                ).toHaveTextContent(
                    'ready',
                );
            });

            expect(
                harness.discover,
            ).toHaveBeenCalledTimes(
                1,
            );

            expect(
                harness.switchMembership,
            ).toHaveBeenCalledTimes(
                1,
            );

            expect(
                harness.switchMembership,
            ).toHaveBeenCalledWith(
                membershipAId,
                expect.anything(),
            );

            expect(
                harness.bootstrapAuthentication,
            ).toHaveBeenCalledWith(
                expect.objectContaining({
                    membershipId:
                        membershipAId,
                }),
            );
        });

        it('requires explicit selection for multiple Memberships after fresh login', async () => {
            const harness =
                renderApplication([
                    membershipA,
                    membershipB,
                ]);

            await submitLogin();

            await waitFor(() => {
                expect(
                    screen.getByTestId(
                        'authentication-status',
                    ),
                ).toHaveTextContent(
                    'identity-authenticated',
                );

                expect(
                    screen.getByTestId(
                        'membership-status',
                    ),
                ).toHaveTextContent(
                    'selection-required',
                );
            });

            expect(
                harness.discover,
            ).toHaveBeenCalledTimes(
                1,
            );

            expect(
                harness.switchMembership,
            ).not.toHaveBeenCalled();

            /*
             * The only auth bootstrap call must be the
             * initial anonymous BrowserSession probe.
             *
             * Fresh multi-Membership login must never
             * silently verify or restore an arbitrary
             * Membership.
             */
            expect(
                harness.bootstrapAuthentication,
            ).toHaveBeenCalledTimes(
                1,
            );
        });
    },
);
