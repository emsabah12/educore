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
    discoverBrowserMemberships,
} from '@/platform/membership';
import { apiMockServer } from '@/test/server';

const membershipAId =
    '018f3b6a-7c20-7abc-8def-1234567890ab';

const membershipBId =
    '018f3b6a-7c20-7bcd-8def-1234567890ab';

const tenantAId =
    '018f3b6a-7c20-7cde-8def-1234567890ab';

const tenantBId =
    '018f3b6a-7c20-7def-8abc-1234567890ab';

const membershipListResponse = {
    status:
        'success' as const,

    data: [
        {
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
};

describe(
    'discoverBrowserMemberships',
    () => {
        it('discovers Person-owned memberships without inventing a membership locator', async () => {
            let observedMembershipLocator:
                string | null =
                    'NOT_CAPTURED';

            apiMockServer.use(
                http.get(
                    `${window.location.origin}/api/v1/user/my-memberships`,
                    ({
                        request,
                    }) => {
                        observedMembershipLocator =
                            request.headers.get(
                                'X-EduCore-Membership-Id',
                            );

                        return HttpResponse.json(
                            membershipListResponse,
                        );
                    },
                ),
            );

            const client =
                createBrowserApiClient();

            const result =
                await discoverBrowserMemberships(
                    client,
                );

            expect(
                observedMembershipLocator,
            ).toBeNull();

            expect(result).toEqual({
                ok: true,
                status: 200,
                data:
                    membershipListResponse,
            });
        });

        it('retries transient network failure during membership discovery', async () => {
            let attempts =
                0;

            apiMockServer.use(
                http.get(
                    `${window.location.origin}/api/v1/user/my-memberships`,
                    () => {
                        attempts +=
                            1;

                        if (
                            attempts
                                === 1
                        ) {
                            return HttpResponse
                                .error();
                        }

                        return HttpResponse.json(
                            membershipListResponse,
                        );
                    },
                ),
            );

            const client =
                createBrowserApiClient();

            const result =
                await discoverBrowserMemberships(
                    client,
                );

            expect(attempts).toBe(2);

            expect(result).toEqual({
                ok: true,
                status: 200,
                data:
                    membershipListResponse,
            });
        });

        it('supports an empty canonical membership discovery result', async () => {
            apiMockServer.use(
                http.get(
                    `${window.location.origin}/api/v1/user/my-memberships`,
                    () => HttpResponse.json({
                        status:
                            'success',
                        data: [],
                    }),
                ),
            );

            const client =
                createBrowserApiClient();

            const result =
                await discoverBrowserMemberships(
                    client,
                );

            expect(result).toEqual({
                ok: true,
                status: 200,
                data: {
                    status:
                        'success',
                    data: [],
                },
            });
        });

        it('preserves BrowserSession authentication-required failures', async () => {
            apiMockServer.use(
                http.get(
                    `${window.location.origin}/api/v1/user/my-memberships`,
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
                await discoverBrowserMemberships(
                    client,
                );

            expect(result).toEqual({
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
            });
        });

        it('preserves BrowserSession context mismatch failures', async () => {
            apiMockServer.use(
                http.get(
                    `${window.location.origin}/api/v1/user/my-memberships`,
                    () => HttpResponse.json(
                        {
                            status:
                                'error',
                            code:
                                'BROWSER_SESSION_CONTEXT_MISMATCH',
                            message:
                                'Browser session context does not match the authenticated identity.',
                        },
                        {
                            status: 403,
                        },
                    ),
                ),
            );

            const client =
                createBrowserApiClient();

            const result =
                await discoverBrowserMemberships(
                    client,
                );

            expect(result).toEqual({
                ok: false,
                kind:
                    'response',
                status: 403,
                error: {
                    status:
                        'error',
                    code:
                        'BROWSER_SESSION_CONTEXT_MISMATCH',
                    message:
                        'Browser session context does not match the authenticated identity.',
                },
            });
        });

        it('propagates cancellation without dispatching membership discovery', async () => {
            let requestWasDispatched =
                false;

            apiMockServer.use(
                http.get(
                    `${window.location.origin}/api/v1/user/my-memberships`,
                    () => {
                        requestWasDispatched =
                            true;

                        return HttpResponse.json(
                            membershipListResponse,
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
                await discoverBrowserMemberships(
                    client,
                    {
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
                    'Expected aborted membership discovery.',
                );
            }

            expect(result.kind).toBe(
                'aborted',
            );
        });
    },
);
