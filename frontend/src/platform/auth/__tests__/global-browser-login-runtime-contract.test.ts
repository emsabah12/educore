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
    type BrowserAuthOperations,
    type BrowserLoginRequest,
    type BrowserLoginSuccess,
} from '@/platform/auth';

const userId =
    '018f3b6a-7c20-7cde-8def-1234567890ab';

const loginRequest:
    BrowserLoginRequest = {
        identifier:
            'school.admin',

        password:
            'correct-horse-battery-staple',
    };

const sessionRequiredFailure:
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
                'Authenticated browser session is required.',
        },
    };

const globalIdentityLoginSuccess:
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
                        'EduCore Admin',

                    email:
                        'admin@example.com',

                    username:
                        'school.admin',
                },

                platform: {
                    is_superadmin:
                        false,
                },
            },
        },
    };

describe(
    'global Browser login runtime contract',
    () => {
        it('stops at identity-authenticated after fresh login without resolving Tenant context', async () => {
            const bootstrap =
                vi.fn(
                    async () =>
                        sessionRequiredFailure,
                );

            const login =
                vi.fn(
                    async () =>
                        globalIdentityLoginSuccess,
                );

            const operations =
                {
                    bootstrap,

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
                        } as const;
                    },
                } satisfies BrowserAuthOperations;

            const runtime =
                createBrowserAuthRuntime(
                    operations,
                );

            const initialState =
                await runtime.bootstrap();

            expect(
                initialState.status,
            ).toBe(
                'anonymous',
            );

            bootstrap.mockClear();

            const observedStatuses:
                string[] = [];

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
                login,
            ).toHaveBeenCalledTimes(
                1,
            );

            expect(
                login,
            ).toHaveBeenCalledWith(
                loginRequest,
                undefined,
            );

            expect(
                bootstrap,
            ).not.toHaveBeenCalled();

            expect(
                observedStatuses,
            ).toEqual([
                'authenticating',
                'identity-authenticated',
            ]);

            expect(
                state,
            ).toEqual({
                status:
                    'identity-authenticated',

                identity:
                    globalIdentityLoginSuccess
                        .data
                        ?.data,
            });

            const serialized =
                JSON.stringify(
                    state,
                );

            expect(
                serialized,
            ).not.toContain(
                'membership_id',
            );

            expect(
                serialized,
            ).not.toContain(
                'tenant_id',
            );

            expect(
                serialized,
            ).not.toContain(
                'access_token',
            );
        });
    },
);
