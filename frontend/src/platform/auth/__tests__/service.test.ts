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
    loginWithBrowserSession,
    type BrowserLoginRequest,
} from '@/platform/auth';
import { apiMockServer } from '@/test/server';

const loginRequest:
    BrowserLoginRequest = {
        email:
            'member@example.com',
        password:
            'correct-horse-battery-staple',
        tenant_uuid:
            '018f3b6a-7c20-7abc-8def-1234567890ab',
    };

const membershipId =
    '018f3b6a-7c20-7bcd-8def-1234567890ab';

const tenantId =
    '018f3b6a-7c20-7cde-8def-1234567890ab';

function clearXsrfCookie(): void {
    document.cookie = [
        'XSRF-TOKEN=',
        'Max-Age=0',
        'Path=/',
    ].join('; ');
}

describe('loginWithBrowserSession', () => {
    beforeEach(() => {
        clearXsrfCookie();
    });

    afterEach(() => {
        clearXsrfCookie();
    });

    it('bootstraps request forgery protection before browser login', async () => {
        const requests: string[] = [];

        let observedLoginBody:
            unknown = null;

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
                        'XSRF-TOKEN=csrf%20token; Path=/';

                    return new HttpResponse(
                        null,
                        {
                            status: 204,
                        },
                    );
                },
            ),

            http.post(
                `${window.location.origin}/api/v1/browser/auth/login`,
                async ({
                    request,
                }) => {
                    requests.push(
                        'login',
                    );

                    observedLoginBody =
                        await request.json();

                    observedXsrfHeader =
                        request.headers.get(
                            'X-XSRF-TOKEN',
                        );

                    return HttpResponse.json({
                        status: 'success',
                        data: {
                            membership_id:
                                membershipId,
                            tenant_id:
                                tenantId,
                        },
                    });
                },
            ),
        );

        const client =
            createBrowserApiClient();

        const result =
            await loginWithBrowserSession(
                client,
                loginRequest,
            );

        expect(requests).toEqual([
            'csrf',
            'login',
        ]);

        expect(
            observedLoginBody,
        ).toEqual(
            loginRequest,
        );

        expect(
            observedXsrfHeader,
        ).toBe(
            'csrf token',
        );

        expect(result).toEqual({
            ok: true,
            status: 200,
            data: {
                status: 'success',
                data: {
                    membership_id:
                        membershipId,
                    tenant_id:
                        tenantId,
                },
            },
        });

        if (! result.ok) {
            throw new Error(
                'Expected successful BrowserSession login.',
            );
        }

        expect(
            result.data,
        ).not.toHaveProperty(
            'data.access_token',
        );
    });

    it('fails closed and does not dispatch login when CSRF bootstrap fails', async () => {
        const requests: string[] = [];

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
                `${window.location.origin}/api/v1/browser/auth/login`,
                () => {
                    requests.push(
                        'login',
                    );

                    return HttpResponse.json({
                        status: 'success',
                        data: {
                            membership_id:
                                membershipId,
                            tenant_id:
                                tenantId,
                        },
                    });
                },
            ),
        );

        const client =
            createBrowserApiClient();

        const result =
            await loginWithBrowserSession(
                client,
                loginRequest,
            );

        expect(requests).toEqual([
            'csrf',
        ]);

        expect(result.ok).toBe(
            false,
        );

        if (result.ok) {
            throw new Error(
                'Expected failed CSRF bootstrap.',
            );
        }

        expect(result.kind).toBe(
            'network',
        );
    });

    it('preserves canonical login validation failures', async () => {
        apiMockServer.use(
            http.get(
                `${window.location.origin}/api/v1/browser/session/csrf`,
                () => {
                    document.cookie =
                        'XSRF-TOKEN=validation-token; Path=/';

                    return new HttpResponse(
                        null,
                        {
                            status: 204,
                        },
                    );
                },
            ),

            http.post(
                `${window.location.origin}/api/v1/browser/auth/login`,
                () => HttpResponse.json(
                    {
                        status: 'error',
                        code:
                            'VALIDATION_FAILED',
                        message:
                            'The submitted data is invalid.',
                        errors: {
                            email: [
                                'The email field is invalid.',
                            ],
                        },
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
            await loginWithBrowserSession(
                client,
                loginRequest,
            );

        expect(result).toEqual({
            ok: false,
            kind: 'response',
            status: 422,
            error: {
                status: 'error',
                code:
                    'VALIDATION_FAILED',
                message:
                    'The submitted data is invalid.',
                errors: {
                    email: [
                        'The email field is invalid.',
                    ],
                },
            },
        });
    });

    it('propagates cancellation through the complete login lifecycle', async () => {
        const requests: string[] = [];

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
                `${window.location.origin}/api/v1/browser/auth/login`,
                () => {
                    requests.push(
                        'login',
                    );

                    return HttpResponse.json({
                        status: 'success',
                        data: {
                            membership_id:
                                membershipId,
                            tenant_id:
                                tenantId,
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
            await loginWithBrowserSession(
                client,
                loginRequest,
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
                'Expected aborted BrowserSession login.',
            );
        }

        expect(result.kind).toBe(
            'aborted',
        );
    });
});
