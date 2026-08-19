import {
    http,
    HttpResponse,
} from 'msw';
import {
    afterEach,
    describe,
    expect,
    it,
} from 'vitest';

import { createBrowserApiClient } from '@/platform/api';
import { apiMockServer } from '@/test/server';

const xsrfCookieName = 'XSRF-TOKEN';

function clearXsrfCookie(): void {
    document.cookie = [
        `${xsrfCookieName}=`,
        'Max-Age=0',
        'Path=/',
    ].join('; ');
}

afterEach(() => {
    clearXsrfCookie();
});

describe('createBrowserApiClient', () => {
    it('uses same-origin credentials without browser authorization material', async () => {
        let observedCredentials: RequestCredentials | null = null;
        let observedAuthorization: string | null = null;
        let observedXsrfToken: string | null = null;

        apiMockServer.use(
            http.get(
                `${window.location.origin}/api/v1/browser/session/csrf`,
                ({
                    request,
                }) => {
                    observedCredentials = request.credentials;

                    observedAuthorization =
                        request.headers.get(
                            'Authorization',
                        );

                    observedXsrfToken =
                        request.headers.get(
                            'X-XSRF-TOKEN',
                        );

                    return new HttpResponse(
                        null,
                        {
                            status: 204,
                        },
                    );
                },
            ),
        );

        const client = createBrowserApiClient();

        const {
            error,
            response,
        } = await client.GET(
            '/api/v1/browser/session/csrf',
        );

        expect(error).toBeUndefined();

        expect(response.status).toBe(204);

        expect(observedCredentials).toBe(
            'same-origin',
        );

        expect(observedAuthorization).toBeNull();

        expect(observedXsrfToken).toBeNull();
    });

    it('reflects the decoded XSRF cookie for browser mutations and strips Authorization', async () => {
        const xsrfToken =
            'educore-csrf+token=value';

        document.cookie = [
            `${xsrfCookieName}=${encodeURIComponent(xsrfToken)}`,
            'Path=/',
        ].join('; ');

        let observedCredentials: RequestCredentials | null = null;
        let observedAuthorization: string | null = null;
        let observedXsrfToken: string | null = null;

        apiMockServer.use(
            http.post(
                `${window.location.origin}/api/v1/browser/auth/logout`,
                ({
                    request,
                }) => {
                    observedCredentials = request.credentials;

                    observedAuthorization =
                        request.headers.get(
                            'Authorization',
                        );

                    observedXsrfToken =
                        request.headers.get(
                            'X-XSRF-TOKEN',
                        );

                    return HttpResponse.json(
                        {
                            status: 'error',
                            code: 'BROWSER_SESSION_UNAVAILABLE',
                            message:
                                'Browser session is unavailable.',
                        },
                        {
                            status: 503,
                        },
                    );
                },
            ),
        );

        const client = createBrowserApiClient();

        const {
            error,
            response,
        } = await client.POST(
            '/api/v1/browser/auth/logout',
            {
                headers: {
                    Authorization:
                        'Bearer browser-must-not-send-this',
                    'X-XSRF-TOKEN':
                        'caller-controlled-token',
                },
            },
        );

        expect(response.status).toBe(503);

        expect(error).toEqual({
            status: 'error',
            code: 'BROWSER_SESSION_UNAVAILABLE',
            message:
                'Browser session is unavailable.',
        });

        expect(observedCredentials).toBe(
            'same-origin',
        );

        expect(observedAuthorization).toBeNull();

        expect(observedXsrfToken).toBe(
            xsrfToken,
        );
    });

    it('does not preserve caller-controlled XSRF headers when the canonical cookie is absent', async () => {
        clearXsrfCookie();

        let observedXsrfToken: string | null = null;

        apiMockServer.use(
            http.post(
                `${window.location.origin}/api/v1/browser/auth/logout`,
                ({
                    request,
                }) => {
                    observedXsrfToken =
                        request.headers.get(
                            'X-XSRF-TOKEN',
                        );

                    return HttpResponse.json(
                        {
                            status: 'error',
                            code: 'BROWSER_SESSION_UNAVAILABLE',
                            message:
                                'Browser session is unavailable.',
                        },
                        {
                            status: 503,
                        },
                    );
                },
            ),
        );

        const client = createBrowserApiClient();

        await client.POST(
            '/api/v1/browser/auth/logout',
            {
                headers: {
                    'X-XSRF-TOKEN':
                        'caller-controlled-token',
                },
            },
        );

        expect(observedXsrfToken).toBeNull();
    });
});
