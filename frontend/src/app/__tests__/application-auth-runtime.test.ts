import {
    http,
    HttpResponse,
} from 'msw';
import {
    describe,
    expect,
    it,
} from 'vitest';

import { createApplicationRuntime } from '@/app/runtime';
import { apiMockServer } from '@/test/server';

describe(
    'Application authentication runtime',
    () => {
        it('composes a single Browser API client with an unknown auth runtime', () => {
            const runtime =
                createApplicationRuntime();

            expect(
                runtime.apiClient,
            ).toBeDefined();

            expect(
                runtime.auth.getState(),
            ).toEqual({
                status:
                    'unknown',
            });
        });

        it('connects the application auth runtime to the canonical Browser API transport', async () => {
            let requestCount =
                0;

            apiMockServer.use(
                http.get(
                    `${window.location.origin}/api/v1/auth/me`,
                    () => {
                        requestCount += 1;

                        return HttpResponse.json(
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
                        );
                    },
                ),
            );

            const runtime =
                createApplicationRuntime();

            expect(
                runtime.auth.getState(),
            ).toEqual({
                status:
                    'unknown',
            });

            expect(
                requestCount,
            ).toBe(0);

            const state =
                await runtime.auth.bootstrap();

            expect(
                requestCount,
            ).toBe(1);

            expect(state).toEqual({
                status:
                    'anonymous',
                failure: {
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
                },
            });
        });
    },
);
