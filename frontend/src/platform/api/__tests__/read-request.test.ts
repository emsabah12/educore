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
    executeBrowserApiReadRequest,
} from '@/platform/api';
import { apiMockServer } from '@/test/server';

describe(
    'executeBrowserApiReadRequest',
    () => {
        it(
            'replays a transient network failure and preserves the eventual success',
            async () => {
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

                            return HttpResponse.json({
                                status: 'success',
                                data: [],
                            });
                        },
                    ),
                );

                const client =
                    createBrowserApiClient();

                const result =
                    await executeBrowserApiReadRequest(
                        () => client.GET(
                            '/api/v1/user/my-memberships',
                        ),
                    );

                expect(attempts).toBe(2);

                expect(result).toEqual({
                    ok: true,
                    status: 200,
                    data: {
                        status: 'success',
                        data: [],
                    },
                });
            },
        );

        it(
            'replays a transient 503 response and preserves the eventual success',
            async () => {
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
                                return HttpResponse.json(
                                    {
                                        status: 'error',
                                        code:
                                            'BROWSER_SESSION_UNAVAILABLE',
                                        message:
                                            'Browser session is temporarily unavailable.',
                                    },
                                    {
                                        status: 503,
                                    },
                                );
                            }

                            return HttpResponse.json({
                                status: 'success',
                                data: [],
                            });
                        },
                    ),
                );

                const client =
                    createBrowserApiClient();

                const result =
                    await executeBrowserApiReadRequest(
                        () => client.GET(
                            '/api/v1/user/my-memberships',
                        ),
                    );

                expect(attempts).toBe(2);

                expect(result.ok).toBe(true);
            },
        );

        it(
            'does not replay a non-retryable response',
            async () => {
                let attempts =
                    0;

                apiMockServer.use(
                    http.get(
                        `${window.location.origin}/api/v1/user/my-memberships`,
                        () => {
                            attempts +=
                                1;

                            return HttpResponse.json(
                                {
                                    status: 'error',
                                    code:
                                        'RESOURCE_NOT_FOUND',
                                    message:
                                        'The requested resource was not found.',
                                },
                                {
                                    status: 404,
                                },
                            );
                        },
                    ),
                );

                const client =
                    createBrowserApiClient();

                const result =
                    await executeBrowserApiReadRequest(
                        () => client.GET(
                            '/api/v1/user/my-memberships',
                        ),
                    );

                expect(attempts).toBe(1);

                expect(result).toEqual({
                    ok: false,
                    kind: 'response',
                    status: 404,
                    error: {
                        status: 'error',
                        code:
                            'RESOURCE_NOT_FOUND',
                        message:
                            'The requested resource was not found.',
                    },
                });
            },
        );

        it(
            'stops after the bounded retry budget is exhausted',
            async () => {
                let attempts =
                    0;

                apiMockServer.use(
                    http.get(
                        `${window.location.origin}/api/v1/user/my-memberships`,
                        () => {
                            attempts +=
                                1;

                            return HttpResponse
                                .error();
                        },
                    ),
                );

                const client =
                    createBrowserApiClient();

                const result =
                    await executeBrowserApiReadRequest(
                        () => client.GET(
                            '/api/v1/user/my-memberships',
                        ),
                    );

                expect(attempts).toBe(3);

                expect(result.ok).toBe(false);

                if (result.ok) {
                    throw new Error(
                        'Expected failed Browser API read result.',
                    );
                }

                expect(result.kind).toBe(
                    'network',
                );
            },
        );

        it(
            'does not replay an aborted request',
            async () => {
                let attempts =
                    0;

                const controller =
                    new AbortController();

                controller.abort();

                const client =
                    createBrowserApiClient();

                const result =
                    await executeBrowserApiReadRequest(
                        () => {
                            attempts +=
                                1;

                            return client.GET(
                                '/api/v1/user/my-memberships',
                                {
                                    signal:
                                        controller.signal,
                                },
                            );
                        },
                    );

                expect(attempts).toBe(1);

                expect(result.ok).toBe(false);

                if (result.ok) {
                    throw new Error(
                        'Expected aborted Browser API read result.',
                    );
                }

                expect(result.kind).toBe(
                    'aborted',
                );
            },
        );
    },
);
