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
} from '@/app/auth/BrowserAuthProvider';
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
} from '@/platform/auth';
import {
    createMembershipContextRuntime,
    type MembershipContextOperations,
    type MembershipListSuccess,
} from '@/platform/membership';

const membershipId =
    '018f3b6a-7c20-7abc-8def-1234567890ab';

const tenantId =
    '018f3b6a-7c20-7cde-8def-1234567890ab';

const membershipBId =
    '018f3b6a-7c20-7bcd-8def-1234567890ab';

const tenantBId =
    '018f3b6a-7c20-7def-8abc-1234567890ab';

const membershipRequiredFailure:
    BrowserApiFailure = {
        ok: false,
        kind:
            'response',
        status: 403,
        error: {
            status:
                'error',
            code:
                'BROWSER_MEMBERSHIP_CONTEXT_REQUIRED',
            message:
                'Browser membership context is required.',
        },
    };

const sessionRequiredFailure:
    BrowserApiFailure = {
        ok: false,
        kind:
            'response',
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
                'Membership discovery cancelled',
            ),
    };

const discoverySuccess:
    BrowserApiResult<
        MembershipListSuccess
    > = {
        ok: true,
        status: 200,
        data: {
            status:
                'success',
            data: [
                {
                    membership_id:
                        membershipId,
                    membership_status:
                        'ACTIVE',
                    tenant_id:
                        tenantId,
                    tenant_name:
                        'EduCore School',
                    tenant_subdomain:
                        'school',
                },
                {
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
                },
            ],
        },
    };

function createAuthOperations(
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

function createMembershipOperations(
    discover:
        MembershipContextOperations['discover'],
): MembershipContextOperations {
    return {
        discover,

        async switchMembership() {
            throw new Error(
                'Unexpected Membership switch operation.',
            );
        },
    };
}

function MembershipStateProbe() {
    const state =
        useMembershipContextState();

    return (
        <output
            data-testid="membership-state"
        >
            {state.status}
        </output>
    );
}

describe(
    'MembershipContextProvider',
    () => {
        it('waits for canonical authentication truth before starting Membership discovery', async () => {
            let resolveAuthentication!:
                (
                    result:
                        BrowserApiResult<
                            AuthenticatedBootstrapSuccess
                        >,
                ) => void;

            const authenticationResult =
                new Promise<
                    BrowserApiResult<
                        AuthenticatedBootstrapSuccess
                    >
                >(
                    (
                        resolve,
                    ) => {
                        resolveAuthentication =
                            resolve;
                    },
                );

            const authentication =
                createBrowserAuthRuntime(
                    createAuthOperations(
                        async () =>
                            authenticationResult,
                    ),
                );

            let discoveryCalls =
                0;

            const membership =
                createMembershipContextRuntime(
                    createMembershipOperations(
                        async () => {
                            discoveryCalls +=
                                1;

                            return discoverySuccess;
                        },
                    ),
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
                        <MembershipStateProbe />
                    </MembershipContextProvider>
                </BrowserAuthProvider>,
            );

            expect(
                screen.getByText(
                    'unresolved',
                ),
            ).toBeInTheDocument();

            expect(
                discoveryCalls,
            ).toBe(0);

            await act(
                async () => {
                    resolveAuthentication(
                        membershipRequiredFailure,
                    );

                    await authenticationResult;
                },
            );

            expect(
                await screen.findByText(
                    'selection-required',
                ),
            ).toBeInTheDocument();

            expect(
                discoveryCalls,
            ).toBe(1);
        });

        it('clears Membership truth when authentication becomes anonymous', async () => {
            const authentication =
                createBrowserAuthRuntime(
                    createAuthOperations(
                        async () =>
                            membershipRequiredFailure,
                    ),
                );

            const membership =
                createMembershipContextRuntime(
                    createMembershipOperations(
                        async () =>
                            discoverySuccess,
                    ),
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
                        <MembershipStateProbe />
                    </MembershipContextProvider>
                </BrowserAuthProvider>,
            );

            expect(
                await screen.findByText(
                    'selection-required',
                ),
            ).toBeInTheDocument();

            act(() => {
                authentication.observeFailure(
                    sessionRequiredFailure,
                );
            });

            expect(
                await screen.findByText(
                    'unresolved',
                ),
            ).toBeInTheDocument();

            expect(
                membership.getState(),
            ).toEqual({
                status:
                    'unresolved',
            });
        });

        it('remains deterministic across StrictMode Membership discovery setup and cleanup', async () => {
            const authentication =
                createBrowserAuthRuntime(
                    createAuthOperations(
                        async () => {
                            throw new Error(
                                'Unexpected authentication bootstrap.',
                            );
                        },
                    ),
                );

            authentication.observeFailure(
                membershipRequiredFailure,
            );

            expect(
                authentication
                    .getState()
                    .status,
            ).toBe(
                'membership-context-required',
            );

            let discoveryCalls =
                0;

            const membership =
                createMembershipContextRuntime(
                    createMembershipOperations(
                        (
                            options,
                        ) => {
                            discoveryCalls +=
                                1;

                            if (
                                discoveryCalls
                                    !== 1
                            ) {
                                return Promise.resolve(
                                    discoverySuccess,
                                );
                            }

                            const signal =
                                options?.signal;

                            if (
                                signal
                                    === undefined
                            ) {
                                throw new Error(
                                    'Expected Membership discovery AbortSignal.',
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
                    authentication,
                );

            render(
                <StrictMode>
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
                            <MembershipStateProbe />
                        </MembershipContextProvider>
                    </BrowserAuthProvider>
                </StrictMode>,
            );

            expect(
                await screen.findByText(
                    'selection-required',
                ),
            ).toBeInTheDocument();

            await waitFor(() => {
                expect(
                    discoveryCalls,
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
                membership.getState(),
            ).toEqual({
                status:
                    'selection-required',
                memberships:
                    discoverySuccess
                        .data
                        ?.data,
                failure:
                    null,
            });

            expect(
                screen.getByText(
                    'selection-required',
                ),
            ).toBeInTheDocument();
        });
    },
);
