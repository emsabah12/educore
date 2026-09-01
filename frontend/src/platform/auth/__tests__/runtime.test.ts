import {
    describe,
    expect,
    it,
    vi,
} from 'vitest';

import type {
    BrowserApiFailure,
    BrowserApiResult,
} from '@/platform/api';
import {
    createBrowserAuthRuntime,
    type AuthenticatedBootstrapSuccess,
    type BrowserAuthOperations,
    type BrowserLoginRequest,
    type BrowserLoginSuccess,
    type BrowserLogoutSuccess,
} from '@/platform/auth';

const membershipId =
    '018f3b6a-7c20-7abc-8def-1234567890ab';

const tenantId =
    '018f3b6a-7c20-7bcd-8def-1234567890ab';

const loginRequest:
    BrowserLoginRequest = {
        identifier:
            'member@example.com',

        password:
            'correct-horse-battery-staple',
    };

const loginSuccess:
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
                        '018f3b6a-7c20-7cde-8def-1234567890ab',

                    name:
                        'EduCore Member',

                    email:
                        'member@example.com',

                    username:
                        'member',
                },

                platform: {
                    is_superadmin:
                        false,
                },
            },
        },
    };

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

const logoutSuccess:
    BrowserApiResult<
        BrowserLogoutSuccess
    > = {
        ok: true,
        status: 200,
        data: {
            status:
                'success',
            message:
                'Logout completed successfully.',
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

const membershipContextRequiredFailure:
    BrowserApiFailure = {
        ok: false,
        kind: 'response',
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

const authenticationContextDeniedFailure:
    BrowserApiFailure = {
        ok: false,
        kind:
            'response',
        status:
            403,
        error: {
            status:
                'error',
            code:
                'AUTHENTICATION_CONTEXT_DENIED',
            message:
                'Authentication context missing or invalid.',
        },
    };

const unavailableFailure:
    BrowserApiFailure = {
        ok: false,
        kind: 'network',
        cause:
            new TypeError(
                'Network unavailable',
            ),
    };

const logoutUnavailableFailure:
    BrowserApiFailure = {
        ok: false,
        kind: 'response',
        status: 503,
        error: {
            status:
                'error',
            code:
                'LOGOUT_UNAVAILABLE',
            message:
                'Logout is temporarily unavailable.',
        },
    };

function createOperations(
    overrides:
        Partial<
            BrowserAuthOperations
        > = {},
): BrowserAuthOperations {
    return {
        async bootstrap() {
            return bootstrapSuccess;
        },

        async login() {
            return loginSuccess;
        },

        async logout() {
            return logoutSuccess;
        },

        ...overrides,
    };
}

describe(
    'BrowserAuthRuntime',
    () => {
        it('bootstraps canonical authenticated identity from unknown state', async () => {
            const runtime =
                createBrowserAuthRuntime(
                    createOperations(),
                );

            expect(
                runtime.getState(),
            ).toEqual({
                status:
                    'unknown',
            });

            const state =
                await runtime.bootstrap();

            expect(state).toEqual({
                status:
                    'authenticated',
                identity:
                    bootstrapSuccess.data?.data,
            });
        });

        it('resolves initial missing BrowserSession to anonymous', async () => {
            const runtime =
                createBrowserAuthRuntime(
                    createOperations({
                        async bootstrap() {
                            return sessionRequiredFailure;
                        },
                    }),
                );

            const state =
                await runtime.bootstrap();

            expect(state).toEqual({
                status:
                    'anonymous',
                failure:
                    sessionRequiredFailure,
            });
        });

        it('keeps membership-context-required distinct during initial bootstrap', async () => {
            const runtime =
                createBrowserAuthRuntime(
                    createOperations({
                        async bootstrap() {
                            return membershipContextRequiredFailure;
                        },
                    }),
                );

            const state =
                await runtime.bootstrap();

            expect(state).toEqual({
                status:
                    'membership-context-required',
                failure:
                    membershipContextRequiredFailure,
            });
        });

        it('stops fresh global login at identity-authenticated without Membership bootstrap', async () => {
            const observedStatuses:
                string[] = [];

            const bootstrap =
                vi.fn(
                    async () =>
                        bootstrapSuccess,
                );

            const runtime =
                createBrowserAuthRuntime(
                    createOperations({
                        bootstrap,
                    }),
                );

            await runtime.bootstrap();

            runtime.observeFailure(
                sessionRequiredFailure,
            );

            bootstrap.mockClear();

            const unsubscribe =
                runtime.subscribe(
                    (state) => {
                        observedStatuses.push(
                            state.status,
                        );
                    },
                );

            const state =
                await runtime.login(
                    loginRequest,
                );

            unsubscribe();

            expect(
                observedStatuses,
            ).toEqual([
                'authenticating',
                'identity-authenticated',
            ]);

            expect(
                bootstrap,
            ).not.toHaveBeenCalled();

            expect(
                state,
            ).toEqual({
                status:
                    'identity-authenticated',

                identity:
                    loginSuccess.data?.data,
            });
        });

        it('returns to anonymous when browser login fails', async () => {
            const authenticationFailure:
                BrowserApiFailure = {
                    ok: false,
                    kind:
                        'response',
                    status: 401,
                    error: {
                        status:
                            'error',
                        code:
                            'AUTHENTICATION_FAILED',
                        message:
                            'Authentication failed.',
                    },
                };

            const bootstrap =
                vi.fn(
                    async () =>
                        bootstrapSuccess,
                );

            const runtime =
                createBrowserAuthRuntime(
                    createOperations({
                        async login() {
                            return authenticationFailure;
                        },

                        bootstrap,
                    }),
                );

            await runtime.bootstrap();

            runtime.observeFailure(
                sessionRequiredFailure,
            );

            const state =
                await runtime.login(
                    loginRequest,
                );

            expect(state).toEqual({
                status:
                    'anonymous',
                failure:
                    authenticationFailure,
            });

            expect(
                bootstrap,
            ).toHaveBeenCalledTimes(
                1,
            );
        });

        it('transitions authenticated logout through logging-out into anonymous', async () => {
            const statuses:
                string[] = [];

            const runtime =
                createBrowserAuthRuntime(
                    createOperations(),
                );

            await runtime.bootstrap();

            const unsubscribe =
                runtime.subscribe(
                    (state) => {
                        statuses.push(
                            state.status,
                        );
                    },
                );

            const state =
                await runtime.logout();

            unsubscribe();

            expect(statuses).toEqual([
                'logging-out',
                'anonymous',
            ]);

            expect(state).toEqual({
                status:
                    'anonymous',
                failure:
                    null,
            });
        });

        it('fails closed when logout outcome is unavailable', async () => {
            const runtime =
                createBrowserAuthRuntime(
                    createOperations({
                        async logout() {
                            return logoutUnavailableFailure;
                        },
                    }),
                );

            await runtime.bootstrap();

            const state =
                await runtime.logout();

            expect(state).toEqual({
                status:
                    'unavailable',
                failure:
                    logoutUnavailableFailure,
            });
        });

        it('observes canonical session expiry without treating it as transport unavailability', async () => {
            const runtime =
                createBrowserAuthRuntime(
                    createOperations(),
                );

            await runtime.bootstrap();

            const state =
                runtime.observeFailure(
                    sessionRequiredFailure,
                );

            expect(state).toEqual({
                status:
                    'anonymous',
                failure:
                    sessionRequiredFailure,
            });
        });

        it('invalidates authenticated application context for canonical authentication context denial', async () => {
            const runtime =
                createBrowserAuthRuntime(
                    createOperations(),
                );

            await runtime.bootstrap();

            const state =
                runtime.observeFailure(
                    authenticationContextDeniedFailure,
                );

            expect(state).toEqual({
                status:
                    'anonymous',
                failure:
                    authenticationContextDeniedFailure,
            });
        });

        it('invalidates authenticated application context when bootstrap receives canonical context denial', async () => {
            let bootstrapAttempt =
                0;

            const runtime =
                createBrowserAuthRuntime(
                    createOperations({
                        async bootstrap() {
                            bootstrapAttempt +=
                                1;

                            if (
                                bootstrapAttempt
                                    === 1
                            ) {
                                return bootstrapSuccess;
                            }

                            return authenticationContextDeniedFailure;
                        },
                    }),
                );

            const authenticatedState =
                await runtime.bootstrap();

            expect(
                authenticatedState.status,
            ).toBe(
                'authenticated',
            );

            const invalidatedState =
                await runtime.bootstrap();

            expect(
                invalidatedState,
            ).toEqual({
                status:
                    'anonymous',
                failure:
                    authenticationContextDeniedFailure,
            });
        });

        it('observes membership context loss without converting the session to anonymous', async () => {
            const runtime =
                createBrowserAuthRuntime(
                    createOperations(),
                );

            await runtime.bootstrap();

            const state =
                runtime.observeFailure(
                    membershipContextRequiredFailure,
                );

            expect(state).toEqual({
                status:
                    'membership-context-required',
                failure:
                    membershipContextRequiredFailure,
            });
        });

        it('does not mutate authentication truth for unrelated observed network failures', async () => {
            const runtime =
                createBrowserAuthRuntime(
                    createOperations(),
                );

            await runtime.bootstrap();

            const before =
                runtime.getState();

            const after =
                runtime.observeFailure(
                    unavailableFailure,
                );

            expect(after).toBe(
                before,
            );

            expect(after.status).toBe(
                'authenticated',
            );
        });

        it('does not mutate authentication truth when bootstrap is cancelled', async () => {
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

            const runtime =
                createBrowserAuthRuntime(
                    createOperations({
                        async bootstrap() {
                            return abortedFailure;
                        },
                    }),
                );

            const before =
                runtime.getState();

            const after =
                await runtime.bootstrap();

            expect(after).toBe(
                before,
            );

            expect(after).toEqual({
                status:
                    'unknown',
            });
        });

        it('recovers unavailable authentication truth through a successful bootstrap retry', async () => {
            let bootstrapAttempt =
                0;

            const runtime =
                createBrowserAuthRuntime(
                    createOperations({
                        async bootstrap() {
                            bootstrapAttempt +=
                                1;

                            if (
                                bootstrapAttempt
                                    === 1
                            ) {
                                return unavailableFailure;
                            }

                            return bootstrapSuccess;
                        },
                    }),
                );

            const unavailableState =
                await runtime.bootstrap();

            expect(
                unavailableState.status,
            ).toBe(
                'unavailable',
            );

            const recoveredState =
                await runtime.bootstrap();

            expect(
                recoveredState.status,
            ).toBe(
                'authenticated',
            );
        });
    },
);
