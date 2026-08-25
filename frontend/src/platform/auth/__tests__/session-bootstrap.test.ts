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
} from '@/platform/auth/bootstrap';
import {
    apiMockServer,
} from '@/test/server';

describe(
    'BrowserSession authentication bootstrap',
    () => {
        it('establishes BrowserSession transport before initial canonical authentication bootstrap', async () => {
            const sequence:
                string[] = [];

            apiMockServer.use(
                http.get(
                    `${window.location.origin}/api/v1/browser/session/csrf`,
                    () => {
                        sequence.push(
                            'session',
                        );

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
                    () => {
                        sequence.push(
                            'auth',
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
                                status:
                                    403,
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
                sequence,
            ).toEqual([
                'session',
                'auth',
            ]);

            expect(
                result,
            ).toEqual({
                ok:
                    false,

                kind:
                    'response',

                status:
                    403,

                error: {
                    status:
                        'error',

                    code:
                        'BROWSER_MEMBERSHIP_CONTEXT_REQUIRED',

                    message:
                        'Browser membership context is required.',
                },
            });
        });

        it('fails closed without probing canonical authentication when BrowserSession initialization fails', async () => {
            let authenticationRequests =
                0;

            apiMockServer.use(
                http.get(
                    `${window.location.origin}/api/v1/browser/session/csrf`,
                    () =>
                        HttpResponse.error(),
                ),

                http.get(
                    `${window.location.origin}/api/v1/auth/me`,
                    () => {
                        authenticationRequests +=
                            1;

                        return HttpResponse.json({
                            status:
                                'success',
                        });
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
                result.ok,
            ).toBe(
                false,
            );

            if (result.ok) {
                throw new Error(
                    'Expected BrowserSession bootstrap failure.',
                );
            }

            expect(
                result.kind,
            ).toBe(
                'network',
            );

            expect(
                authenticationRequests,
            ).toBe(
                0,
            );
        });
    },
);
