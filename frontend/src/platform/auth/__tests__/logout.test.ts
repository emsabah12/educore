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
    logoutBrowserSession,
} from '@/platform/auth';
import { apiMockServer } from '@/test/server';

function clearXsrfCookie(): void {
    document.cookie = [
        'XSRF-TOKEN=',
        'Max-Age=0',
        'Path=/',
    ].join('; ');
}

describe(
    'logoutBrowserSession',
    () => {
        beforeEach(() => {
            clearXsrfCookie();
        });

        afterEach(() => {
            clearXsrfCookie();
        });

        it('bootstraps request forgery protection before logout', async () => {
            const requests:
                string[] = [];

            let observedXsrfHeader:
                string | null = null;

            apiMockServer.use(
                http.get(
                    `${window.location.origin}/api/v1/browser/session/csrf`,
                    () => {
                        requests.push(
                            'csrf',
                        );

                        document.cookie =
                            'XSRF-TOKEN=logout%20token; Path=/';

                        return new HttpResponse(
                            null,
                            {
                                status: 204,
                            },
                        );
                    },
                ),

                http.post(
                    `${window.location.origin}/api/v1/browser/auth/logout`,
                    ({
                        request,
                    }) => {
                        requests.push(
                            'logout',
                        );

                        observedXsrfHeader =
                            request.headers.get(
                                'X-XSRF-TOKEN',
                            );

                        return HttpResponse.json({
                            status:
                                'success',
                            message:
                                'Logout completed successfully.',
                        });
                    },
                ),
            );

            const client =
                createBrowserApiClient();

            const result =
                await logoutBrowserSession(
                    client,
                );

            expect(requests).toEqual([
                'csrf',
                'logout',
            ]);

            expect(
                observedXsrfHeader,
            ).toBe(
                'logout token',
            );

            expect(result).toEqual({
                ok: true,
                status: 200,
                data: {
                    status:
                        'success',
                    message:
                        'Logout completed successfully.',
                },
            });
        });

        it('fails closed and does not dispatch logout when CSRF bootstrap fails', async () => {
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
                    `${window.location.origin}/api/v1/browser/auth/logout`,
                    () => {
                        requests.push(
                            'logout',
                        );

                        return HttpResponse.json({
                            status:
                                'success',
                            message:
                                'Logout completed successfully.',
                        });
                    },
                ),
            );

            const client =
                createBrowserApiClient();

            const result =
                await logoutBrowserSession(
                    client,
                );

            expect(requests).toEqual([
                'csrf',
            ]);

            expect(result.ok).toBe(
                false,
            );

            if (result.ok) {
                throw new Error(
                    'Expected failed logout CSRF bootstrap.',
                );
            }

            expect(result.kind).toBe(
                'network',
            );
        });

        it('preserves canonical logout-unavailable failures', async () => {
            apiMockServer.use(
                http.get(
                    `${window.location.origin}/api/v1/browser/session/csrf`,
                    () => {
                        document.cookie =
                            'XSRF-TOKEN=logout-unavailable; Path=/';

                        return new HttpResponse(
                            null,
                            {
                                status: 204,
                            },
                        );
                    },
                ),

                http.post(
                    `${window.location.origin}/api/v1/browser/auth/logout`,
                    () => HttpResponse.json(
                        {
                            status:
                                'error',
                            code:
                                'LOGOUT_UNAVAILABLE',
                            message:
                                'Logout is temporarily unavailable.',
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
                await logoutBrowserSession(
                    client,
                );

            expect(result).toEqual({
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
            });
        });

        it('preserves internal logout failures without converting them to anonymous state', async () => {
            apiMockServer.use(
                http.get(
                    `${window.location.origin}/api/v1/browser/session/csrf`,
                    () => {
                        document.cookie =
                            'XSRF-TOKEN=logout-internal; Path=/';

                        return new HttpResponse(
                            null,
                            {
                                status: 204,
                            },
                        );
                    },
                ),

                http.post(
                    `${window.location.origin}/api/v1/browser/auth/logout`,
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
                await logoutBrowserSession(
                    client,
                );

            expect(result).toEqual({
                ok: false,
                kind: 'response',
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

        it('propagates cancellation without dispatching either logout request', async () => {
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
                    `${window.location.origin}/api/v1/browser/auth/logout`,
                    () => {
                        requests.push(
                            'logout',
                        );

                        return HttpResponse.json({
                            status:
                                'success',
                            message:
                                'Logout completed successfully.',
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
                await logoutBrowserSession(
                    client,
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
                    'Expected aborted BrowserSession logout.',
                );
            }

            expect(result.kind).toBe(
                'aborted',
            );
        });
    },
);
