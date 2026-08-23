import {
    describe,
    expect,
    it,
} from 'vitest';

import {
    shouldRetryBrowserApiReadFailure,
    type BrowserApiFailure,
} from '@/platform/api';

function responseFailure(
    status: number,
): BrowserApiFailure {
    return {
        ok: false,
        kind: 'response',
        status,
        error: {
            status: 'error',
            code: 'TEST_FAILURE',
            message:
                'The request failed.',
        },
    };
}

const networkFailure:
    BrowserApiFailure = {
        ok: false,
        kind: 'network',
        cause:
            new TypeError(
                'Failed to fetch.',
            ),
    };

const abortedFailure:
    BrowserApiFailure = {
        ok: false,
        kind: 'aborted',
        cause:
            new DOMException(
                'The operation was aborted.',
                'AbortError',
            ),
    };

const protocolFailure:
    BrowserApiFailure = {
        ok: false,
        kind: 'protocol',
        status: 502,
        message:
            'EduCore API returned an unexpected error response.',
    };

describe(
    'Browser API read retry policy',
    () => {
        it(
            'retries transient network failures within a bounded budget',
            () => {
                expect(
                    shouldRetryBrowserApiReadFailure(
                        networkFailure,
                        0,
                    ),
                ).toBe(true);

                expect(
                    shouldRetryBrowserApiReadFailure(
                        networkFailure,
                        1,
                    ),
                ).toBe(true);

                expect(
                    shouldRetryBrowserApiReadFailure(
                        networkFailure,
                        2,
                    ),
                ).toBe(false);
            },
        );

        it.each([
            502,
            503,
            504,
        ])(
            'retries transient HTTP %i responses within the bounded budget',
            (status) => {
                const failure =
                    responseFailure(
                        status,
                    );

                expect(
                    shouldRetryBrowserApiReadFailure(
                        failure,
                        0,
                    ),
                ).toBe(true);

                expect(
                    shouldRetryBrowserApiReadFailure(
                        failure,
                        1,
                    ),
                ).toBe(true);

                expect(
                    shouldRetryBrowserApiReadFailure(
                        failure,
                        2,
                    ),
                ).toBe(false);
            },
        );

        it.each([
            401,
            403,
            404,
            409,
            422,
            429,
            500,
        ])(
            'does not generically retry HTTP %i responses',
            (status) => {
                expect(
                    shouldRetryBrowserApiReadFailure(
                        responseFailure(
                            status,
                        ),
                        0,
                    ),
                ).toBe(false);
            },
        );

        it(
            'does not retry cancelled requests',
            () => {
                expect(
                    shouldRetryBrowserApiReadFailure(
                        abortedFailure,
                        0,
                    ),
                ).toBe(false);
            },
        );

        it(
            'does not retry malformed protocol responses',
            () => {
                expect(
                    shouldRetryBrowserApiReadFailure(
                        protocolFailure,
                        0,
                    ),
                ).toBe(false);
            },
        );
    },
);
