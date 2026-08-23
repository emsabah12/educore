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
    executeBrowserApiRequest,
} from '@/platform/api';
import { apiMockServer } from '@/test/server';

describe('executeBrowserApiRequest', () => {
    it('preserves successful API responses', async () => {
        apiMockServer.use(
            http.get(
                `${window.location.origin}/api/v1/user/my-memberships`,
                () => HttpResponse.json({
                    status: 'success',
                    data: [],
                }),
            ),
        );

        const client =
            createBrowserApiClient();

        const result =
            await executeBrowserApiRequest(
                client.GET(
                    '/api/v1/user/my-memberships',
                ),
            );

        expect(result.ok).toBe(true);

        if (! result.ok) {
            throw new Error(
                'Expected successful Browser API result.',
            );
        }

        expect(result.status).toBe(200);

        expect(result.data).toEqual({
            status: 'success',
            data: [],
        });
    });

    it('preserves canonical API errors and HTTP status', async () => {
        apiMockServer.use(
            http.get(
                `${window.location.origin}/api/v1/user/my-memberships`,
                () => HttpResponse.json(
                    {
                        status: 'error',
                        code:
                            'BROWSER_SESSION_AUTHENTICATION_REQUIRED',
                        message:
                            'Browser session authentication is required.',
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
            await executeBrowserApiRequest(
                client.GET(
                    '/api/v1/user/my-memberships',
                ),
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
                    'Browser session authentication is required.',
            },
        });
    });

    it('preserves unknown stable API error codes for forward compatibility', async () => {
        apiMockServer.use(
            http.get(
                `${window.location.origin}/api/v1/user/my-memberships`,
                () => HttpResponse.json(
                    {
                        status: 'error',
                        code:
                            'NEW_DOMAIN_CONFLICT',
                        message:
                            'The request conflicts with newer server state.',
                    },
                    {
                        status: 409,
                    },
                ),
            ),
        );

        const client =
            createBrowserApiClient();

        const result =
            await executeBrowserApiRequest(
                client.GET(
                    '/api/v1/user/my-memberships',
                ),
            );

        expect(result).toEqual({
            ok: false,
            kind: 'response',
            status: 409,
            error: {
                status: 'error',
                code:
                    'NEW_DOMAIN_CONFLICT',
                message:
                    'The request conflicts with newer server state.',
            },
        });
    });

    it('preserves canonical validation errors', async () => {
        apiMockServer.use(
            http.post(
                `${window.location.origin}/api/v1/browser/auth/login`,
                () => HttpResponse.json(
                    {
                        status: 'error',
                        code: 'VALIDATION_FAILED',
                        /*
                         * Human-readable backend copy is not
                         * frontend branching authority.
                         *
                         * Recognition of VALIDATION_FAILED must
                         * rely on stable contract fields and the
                         * validation error shape instead.
                         */
                        message:
                            'Some submitted values are invalid.',
                        errors: {
                            email: [
                                'The email field is required.',
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
            await executeBrowserApiRequest(
                client.POST(
                    '/api/v1/browser/auth/login',
                    {
                        body: {
                            email: '',
                            password: '',
                            tenant_uuid:
                                '018f3b6a-7c20-7abc-8def-1234567890ab',
                        },
                    },
                ),
            );

        expect(result).toEqual({
            ok: false,
            kind: 'response',
            status: 422,
            error: {
                status: 'error',
                code: 'VALIDATION_FAILED',
                message:
                    'Some submitted values are invalid.',
                errors: {
                    email: [
                        'The email field is required.',
                    ],
                },
            },
        });
    });

    it('fails safely for malformed API error responses', async () => {
        apiMockServer.use(
            http.get(
                `${window.location.origin}/api/v1/user/my-memberships`,
                () => HttpResponse.json(
                    {
                        message:
                            'Internal implementation details.',
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
            await executeBrowserApiRequest(
                client.GET(
                    '/api/v1/user/my-memberships',
                ),
            );

        expect(result).toEqual({
            ok: false,
            kind: 'protocol',
            status: 500,
            message:
                'EduCore API returned an unexpected error response.',
        });
    });

    it('normalizes aborted requests separately from network failures', async () => {
        const controller =
            new AbortController();

        controller.abort();

        const client =
            createBrowserApiClient();

        const result =
            await executeBrowserApiRequest(
                client.GET(
                    '/api/v1/user/my-memberships',
                    {
                        signal:
                            controller.signal,
                    },
                ),
            );

        expect(result.ok).toBe(false);

        if (result.ok) {
            throw new Error(
                'Expected aborted Browser API result.',
            );
        }

        expect(result.kind).toBe(
            'aborted',
        );
    });

    it('normalizes network failures without inventing an HTTP status', async () => {
        apiMockServer.use(
            http.get(
                `${window.location.origin}/api/v1/user/my-memberships`,
                () => HttpResponse.error(),
            ),
        );

        const client =
            createBrowserApiClient();

        const result =
            await executeBrowserApiRequest(
                client.GET(
                    '/api/v1/user/my-memberships',
                ),
            );

        expect(result.ok).toBe(false);

        if (result.ok) {
            throw new Error(
                'Expected network Browser API result.',
            );
        }

        expect(result.kind).toBe(
            'network',
        );

        expect(
            'status' in result,
        ).toBe(false);
    });
});
