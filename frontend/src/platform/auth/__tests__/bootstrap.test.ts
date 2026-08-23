import {
    http,
    HttpResponse,
} from 'msw';
import {
    describe,
    expect,
    it,
} from 'vitest';

import {
    createBrowserApiClient,
} from '@/platform/api';
import {
    bootstrapBrowserAuthentication,
} from '@/platform/auth';
import { apiMockServer } from '@/test/server';

const membershipId =
    '018f3b6a-7c20-7abc-8def-1234567890ab';

const userId =
    '018f3b6a-7c20-7bcd-8def-1234567890ab';

const personId =
    '018f3b6a-7c20-7cde-8def-1234567890ab';

const tenantId =
    '018f3b6a-7c20-7def-8abc-1234567890ab';

const authenticatedResponse = {
    status: 'success' as const,

    data: {
        user: {
            id: userId,
            email:
                'member@example.com',
        },

        person: {
            id: personId,
            name:
                'EduCore Member',
        },

        membership: {
            id: membershipId,
            status:
                'ACTIVE' as const,
        },

        tenant: {
            id: tenantId,
            name:
                'EduCore School',
            subdomain:
                'school',
        },
    },
};

describe(
    'bootstrapBrowserAuthentication',
    () => {
        it('resolves canonical authenticated identity with an explicit membership locator', async () => {
            let observedMembershipId:
                string | null = null;

            apiMockServer.use(
                http.get(
                    `${window.location.origin}/api/v1/auth/me`,
                    ({
                        request,
                    }) => {
                        observedMembershipId =
                            request.headers.get(
                                'X-EduCore-Membership-Id',
                            );

                        return HttpResponse.json(
                            authenticatedResponse,
                        );
                    },
                ),
            );

            const client =
                createBrowserApiClient();

            const result =
                await bootstrapBrowserAuthentication(
                    client,
                    {
                        membershipId,
                    },
                );

            expect(
                observedMembershipId,
            ).toBe(
                membershipId,
            );

            expect(result).toEqual({
                ok: true,
                status: 200,
                data:
                    authenticatedResponse,
            });
        });

        it('does not invent a membership locator when none is available', async () => {
            let observedMembershipId:
                string | null =
                    'NOT_CAPTURED';

            apiMockServer.use(
                http.get(
                    `${window.location.origin}/api/v1/auth/me`,
                    ({
                        request,
                    }) => {
                        observedMembershipId =
                            request.headers.get(
                                'X-EduCore-Membership-Id',
                            );

                        return HttpResponse.json(
                            {
                                status:
                                    'error',
                                code:
                                    'BROWSER_MEMBERSHIP_CONTEXT_REQUIRED',
                                message:
                                    'Browser membership context is required.',
                            },
                            {
                                status: 403,
                            },
                        );
                    },
                ),
            );

            const client =
                createBrowserApiClient();

            const result =
                await bootstrapBrowserAuthentication(
                    client,
                );

            expect(
                observedMembershipId,
            ).toBeNull();

            expect(result).toEqual({
                ok: false,
                kind: 'response',
                status: 403,
                error: {
                    status: 'error',
                    code:
                        'BROWSER_MEMBERSHIP_CONTEXT_REQUIRED',
                    message:
                        'Browser membership context is required.',
                },
            });
        });

        it('retries transient initial authentication context failure without replaying BrowserSession bootstrap', async () => {
            let sessionAttempts =
                0;

            let authenticationAttempts =
                0;

            const observedMembershipIds:
                Array<string | null> = [];

            apiMockServer.use(
                http.get(
                    `${window.location.origin}/api/v1/browser/session/csrf`,
                    () => {
                        sessionAttempts +=
                            1;

                        return new HttpResponse(
                            null,
                            {
                                status:
                                    204,
                            },
                        );
                    },
                ),

                http.get(
                    `${window.location.origin}/api/v1/auth/me`,
                    ({
                        request,
                    }) => {
                        authenticationAttempts +=
                            1;

                        observedMembershipIds.push(
                            request.headers.get(
                                'X-EduCore-Membership-Id',
                            ),
                        );

                        if (
                            authenticationAttempts
                                === 1
                        ) {
                            return HttpResponse
                                .error();
                        }

                        return HttpResponse.json(
                            {
                                status:
                                    'error',
                                code:
                                    'BROWSER_MEMBERSHIP_CONTEXT_REQUIRED',
                                message:
                                    'Browser membership context is required.',
                            },
                            {
                                status:
                                    403,
                            },
                        );
                    },
                ),
            );

            const result =
                await bootstrapBrowserAuthentication(
                    createBrowserApiClient(),
                );

            expect(
                sessionAttempts,
            ).toBe(1);

            expect(
                authenticationAttempts,
            ).toBe(2);

            expect(
                observedMembershipIds,
            ).toEqual([
                null,
                null,
            ]);

            expect(result).toMatchObject({
                ok:
                    false,
                kind:
                    'response',
                status:
                    403,
                error: {
                    code:
                        'BROWSER_MEMBERSHIP_CONTEXT_REQUIRED',
                },
            });
        });

        it('retries transient Membership authentication context failure while preserving the Membership locator', async () => {
            let authenticationAttempts =
                0;

            let sessionAttempts =
                0;

            const observedMembershipIds:
                Array<string | null> = [];

            apiMockServer.use(
                http.get(
                    `${window.location.origin}/api/v1/browser/session/csrf`,
                    () => {
                        sessionAttempts +=
                            1;

                        return new HttpResponse(
                            null,
                            {
                                status:
                                    204,
                            },
                        );
                    },
                ),

                http.get(
                    `${window.location.origin}/api/v1/auth/me`,
                    ({
                        request,
                    }) => {
                        authenticationAttempts +=
                            1;

                        observedMembershipIds.push(
                            request.headers.get(
                                'X-EduCore-Membership-Id',
                            ),
                        );

                        if (
                            authenticationAttempts
                                === 1
                        ) {
                            return HttpResponse
                                .error();
                        }

                        return HttpResponse.json(
                            authenticatedResponse,
                        );
                    },
                ),
            );

            const result =
                await bootstrapBrowserAuthentication(
                    createBrowserApiClient(),
                    {
                        membershipId,
                    },
                );

            expect(
                sessionAttempts,
            ).toBe(0);

            expect(
                authenticationAttempts,
            ).toBe(2);

            expect(
                observedMembershipIds,
            ).toEqual([
                membershipId,
                membershipId,
            ]);

            expect(result).toEqual({
                ok:
                    true,
                status:
                    200,
                data:
                    authenticatedResponse,
            });
        });

        it('preserves BrowserSession authentication-required failures', async () => {
            apiMockServer.use(
                http.get(
                    `${window.location.origin}/api/v1/auth/me`,
                    () => HttpResponse.json(
                        {
                            status:
                                'error',
                            code:
                                'BROWSER_SESSION_AUTHENTICATION_REQUIRED',
                            message:
                                'Authenticated browser session is required.',
                        },
                        {
                            status: 401,
                        },
                    ),
                ),
            );

            const client =
                createBrowserApiClient();

            const result =
                await bootstrapBrowserAuthentication(
                    client,
                    {
                        membershipId,
                    },
                );

            expect(result).toEqual({
                ok: false,
                kind: 'response',
                status: 401,
                error: {
                    status: 'error',
                    code:
                        'BROWSER_SESSION_AUTHENTICATION_REQUIRED',
                    message:
                        'Authenticated browser session is required.',
                },
            });
        });

        it('preserves invalid membership locator failures', async () => {
            apiMockServer.use(
                http.get(
                    `${window.location.origin}/api/v1/auth/me`,
                    () => HttpResponse.json(
                        {
                            status:
                                'error',
                            code:
                                'INVALID_BROWSER_MEMBERSHIP_ID',
                            message:
                                'Browser membership identifier is invalid.',
                        },
                        {
                            status: 422,
                        },
                    ),
                ),
            );

            const client =
                createBrowserApiClient();

            const result =
                await bootstrapBrowserAuthentication(
                    client,
                    {
                        membershipId:
                            'not-a-uuid',
                    },
                );

            expect(result).toEqual({
                ok: false,
                kind: 'response',
                status: 422,
                error: {
                    status: 'error',
                    code:
                        'INVALID_BROWSER_MEMBERSHIP_ID',
                    message:
                        'Browser membership identifier is invalid.',
                },
            });
        });

        it('propagates cancellation without dispatching the bootstrap request', async () => {
            let requestWasDispatched =
                false;

            apiMockServer.use(
                http.get(
                    `${window.location.origin}/api/v1/auth/me`,
                    () => {
                        requestWasDispatched =
                            true;

                        return HttpResponse.json(
                            authenticatedResponse,
                        );
                    },
                ),
            );

            const controller =
                new AbortController();

            controller.abort();

            const client =
                createBrowserApiClient();

            const result =
                await bootstrapBrowserAuthentication(
                    client,
                    {
                        membershipId,
                        signal:
                            controller.signal,
                    },
                );

            expect(
                requestWasDispatched,
            ).toBe(false);

            expect(result.ok).toBe(
                false,
            );

            if (result.ok) {
                throw new Error(
                    'Expected aborted authentication bootstrap.',
                );
            }

            expect(result.kind).toBe(
                'aborted',
            );
        });
    },
);
