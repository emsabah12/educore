import {
    http,
    HttpResponse,
} from 'msw';
import {
    afterEach,
    beforeEach,
    describe,
    expect,
    it,
} from 'vitest';

import {
    createBrowserApiClient,
} from '@/platform/api';
import {
    switchBrowserMembership,
} from '@/platform/membership';
import { apiMockServer } from '@/test/server';

const membershipId =
    '018f3b6a-7c20-7abc-8def-1234567890ab';

const tenantId =
    '018f3b6a-7c20-7bcd-8def-1234567890ab';

function clearXsrfCookie(): void {
    document.cookie = [
        'XSRF-TOKEN=',
        'Max-Age=0',
        'Path=/',
    ].join('; ');
}

describe(
    'switchBrowserMembership',
    () => {
        beforeEach(() => {
            clearXsrfCookie();
        });

        afterEach(() => {
            clearXsrfCookie();
        });

        it('bootstraps request forgery protection before preparing the target Membership credential', async () => {
            const requests:
                string[] = [];

            let observedXsrfHeader:
                string | null = null;

            let observedSwitchPath:
                string | null = null;

            apiMockServer.use(
                http.get(
                    `${window.location.origin}/api/v1/browser/session/csrf`,
                    () => {
                        requests.push(
                            'csrf',
                        );

                        document.cookie =
                            'XSRF-TOKEN=membership%20switch; Path=/';

                        return new HttpResponse(
                            null,
                            {
                                status: 204,
                            },
                        );
                    },
                ),

                http.post(
                    `${window.location.origin}/api/v1/browser/user/memberships/${membershipId}/switch`,
                    ({
                        request,
                    }) => {
                        requests.push(
                            'switch',
                        );

                        observedXsrfHeader =
                            request.headers.get(
                                'X-XSRF-TOKEN',
                            );

                        observedSwitchPath =
                            new URL(
                                request.url,
                            ).pathname;

                        return HttpResponse.json({
                            status:
                                'success',
                            data: {
                                membership_id:
                                    membershipId,
                                tenant_id:
                                    tenantId,
                                tenant_name:
                                    'EduCore School B',
                            },
                        });
                    },
                ),
            );

            const client =
                createBrowserApiClient();

            const result =
                await switchBrowserMembership(
                    client,
                    membershipId,
                );

            expect(requests).toEqual([
                'csrf',
                'switch',
            ]);

            expect(
                observedXsrfHeader,
            ).toBe(
                'membership switch',
            );

            expect(
                observedSwitchPath,
            ).toBe(
                `/api/v1/browser/user/memberships/${membershipId}/switch`,
            );

            expect(result).toEqual({
                ok: true,
                status: 200,
                data: {
                    status:
                        'success',
                    data: {
                        membership_id:
                            membershipId,
                        tenant_id:
                            tenantId,
                        tenant_name:
                            'EduCore School B',
                    },
                },
            });
        });

        it('fails closed without dispatching switch when CSRF bootstrap fails', async () => {
            const requests:
                string[] = [];

            apiMockServer.use(
                http.get(
                    `${window.location.origin}/api/v1/browser/session/csrf`,
                    () => {
                        requests.push(
                            'csrf',
                        );

                        return HttpResponse.error();
                    },
                ),

                http.post(
                    `${window.location.origin}/api/v1/browser/user/memberships/${membershipId}/switch`,
                    () => {
                        requests.push(
                            'switch',
                        );

                        return HttpResponse.json({
                            status:
                                'success',
                            data: {
                                membership_id:
                                    membershipId,
                                tenant_id:
                                    tenantId,
                                tenant_name:
                                    'EduCore School B',
                            },
                        });
                    },
                ),
            );

            const client =
                createBrowserApiClient();

            const result =
                await switchBrowserMembership(
                    client,
                    membershipId,
                );

            expect(requests).toEqual([
                'csrf',
            ]);

            expect(result.ok).toBe(
                false,
            );

            if (result.ok) {
                throw new Error(
                    'Expected failed membership switch CSRF bootstrap.',
                );
            }

            expect(result.kind).toBe(
                'network',
            );
        });

        it('preserves BrowserSession authentication-required failures', async () => {
            apiMockServer.use(
                http.get(
                    `${window.location.origin}/api/v1/browser/session/csrf`,
                    () => {
                        document.cookie =
                            'XSRF-TOKEN=switch-auth; Path=/';

                        return new HttpResponse(
                            null,
                            {
                                status: 204,
                            },
                        );
                    },
                ),

                http.post(
                    `${window.location.origin}/api/v1/browser/user/memberships/${membershipId}/switch`,
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
                await switchBrowserMembership(
                    client,
                    membershipId,
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

        it('preserves membership-switch-denied failures', async () => {
            apiMockServer.use(
                http.get(
                    `${window.location.origin}/api/v1/browser/session/csrf`,
                    () => {
                        document.cookie =
                            'XSRF-TOKEN=switch-denied; Path=/';

                        return new HttpResponse(
                            null,
                            {
                                status: 204,
                            },
                        );
                    },
                ),

                http.post(
                    `${window.location.origin}/api/v1/browser/user/memberships/${membershipId}/switch`,
                    () => HttpResponse.json(
                        {
                            status:
                                'error',
                            code:
                                'MEMBERSHIP_SWITCH_DENIED',
                            message:
                                'Requested membership is unavailable.',
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
                await switchBrowserMembership(
                    client,
                    membershipId,
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
                        'MEMBERSHIP_SWITCH_DENIED',
                    message:
                        'Requested membership is unavailable.',
                },
            });
        });

        it('preserves invalid browser Membership locator failures', async () => {
            apiMockServer.use(
                http.get(
                    `${window.location.origin}/api/v1/browser/session/csrf`,
                    () => {
                        document.cookie =
                            'XSRF-TOKEN=switch-invalid; Path=/';

                        return new HttpResponse(
                            null,
                            {
                                status: 204,
                            },
                        );
                    },
                ),

                http.post(
                    `${window.location.origin}/api/v1/browser/user/memberships/${membershipId}/switch`,
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
                await switchBrowserMembership(
                    client,
                    membershipId,
                );

            expect(result).toEqual({
                ok: false,
                kind:
                    'response',
                status: 422,
                error: {
                    status:
                        'error',
                    code:
                        'INVALID_BROWSER_MEMBERSHIP_ID',
                    message:
                        'Browser membership identifier is invalid.',
                },
            });
        });

        it('preserves BrowserSession unavailable failures', async () => {
            apiMockServer.use(
                http.get(
                    `${window.location.origin}/api/v1/browser/session/csrf`,
                    () => {
                        document.cookie =
                            'XSRF-TOKEN=switch-unavailable; Path=/';

                        return new HttpResponse(
                            null,
                            {
                                status: 204,
                            },
                        );
                    },
                ),

                http.post(
                    `${window.location.origin}/api/v1/browser/user/memberships/${membershipId}/switch`,
                    () => HttpResponse.json(
                        {
                            status:
                                'error',
                            code:
                                'BROWSER_SESSION_UNAVAILABLE',
                            message:
                                'Browser session is temporarily unavailable.',
                        },
                        {
                            status: 503,
                        },
                    ),
                ),
            );

            const client =
                createBrowserApiClient();

            const result =
                await switchBrowserMembership(
                    client,
                    membershipId,
                );

            expect(result).toEqual({
                ok: false,
                kind:
                    'response',
                status: 503,
                error: {
                    status:
                        'error',
                    code:
                        'BROWSER_SESSION_UNAVAILABLE',
                    message:
                        'Browser session is temporarily unavailable.',
                },
            });
        });

        it('preserves unexpected internal switch failures', async () => {
            apiMockServer.use(
                http.get(
                    `${window.location.origin}/api/v1/browser/session/csrf`,
                    () => {
                        document.cookie =
                            'XSRF-TOKEN=switch-internal; Path=/';

                        return new HttpResponse(
                            null,
                            {
                                status: 204,
                            },
                        );
                    },
                ),

                http.post(
                    `${window.location.origin}/api/v1/browser/user/memberships/${membershipId}/switch`,
                    () => HttpResponse.json(
                        {
                            status:
                                'error',
                            code:
                                'INTERNAL_SERVER_ERROR',
                            message:
                                'An internal server error occurred.',
                        },
                        {
                            status: 500,
                        },
                    ),
                ),
            );

            const client =
                createBrowserApiClient();

            const result =
                await switchBrowserMembership(
                    client,
                    membershipId,
                );

            expect(result).toEqual({
                ok: false,
                kind:
                    'response',
                status: 500,
                error: {
                    status:
                        'error',
                    code:
                        'INTERNAL_SERVER_ERROR',
                    message:
                        'An internal server error occurred.',
                },
            });
        });

        it('propagates cancellation without dispatching either switch request', async () => {
            const requests:
                string[] = [];

            apiMockServer.use(
                http.get(
                    `${window.location.origin}/api/v1/browser/session/csrf`,
                    () => {
                        requests.push(
                            'csrf',
                        );

                        return new HttpResponse(
                            null,
                            {
                                status: 204,
                            },
                        );
                    },
                ),

                http.post(
                    `${window.location.origin}/api/v1/browser/user/memberships/${membershipId}/switch`,
                    () => {
                        requests.push(
                            'switch',
                        );

                        return HttpResponse.json({
                            status:
                                'success',
                            data: {
                                membership_id:
                                    membershipId,
                                tenant_id:
                                    tenantId,
                                tenant_name:
                                    'EduCore School B',
                            },
                        });
                    },
                ),
            );

            const controller =
                new AbortController();

            controller.abort();

            const client =
                createBrowserApiClient();

            const result =
                await switchBrowserMembership(
                    client,
                    membershipId,
                    {
                        signal:
                            controller.signal,
                    },
                );

            expect(requests).toEqual([]);

            expect(result.ok).toBe(
                false,
            );

            if (result.ok) {
                throw new Error(
                    'Expected aborted Browser Membership switch.',
                );
            }

            expect(result.kind).toBe(
                'aborted',
            );
        });
    },
);
