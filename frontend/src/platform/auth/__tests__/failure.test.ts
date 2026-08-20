import {
    describe,
    expect,
    it,
} from 'vitest';

import type {
    BrowserApiFailure,
} from '@/platform/api';
import {
    isBrowserMembershipContextRequiredFailure,
    isBrowserSessionAuthenticationRequiredFailure,
} from '@/platform/auth';

describe(
    'Browser authentication failure classification',
    () => {
        it('classifies canonical BrowserSession authentication loss', () => {
            const failure:
                BrowserApiFailure = {
                    ok: false,
                    kind: 'response',
                    status: 401,
                    error: {
                        status:
                            'error',
                        code:
                            'BROWSER_SESSION_AUTHENTICATION_REQUIRED',
                        message:
                            'Authenticated browser session is required.',
                    },
                };

            expect(
                isBrowserSessionAuthenticationRequiredFailure(
                    failure,
                ),
            ).toBe(true);

            expect(
                isBrowserMembershipContextRequiredFailure(
                    failure,
                ),
            ).toBe(false);
        });

        it('classifies missing membership context separately from session loss', () => {
            const failure:
                BrowserApiFailure = {
                    ok: false,
                    kind: 'response',
                    status: 403,
                    error: {
                        status:
                            'error',
                        code:
                            'BROWSER_MEMBERSHIP_CONTEXT_REQUIRED',
                        message:
                            'Browser membership context is required.',
                    },
                };

            expect(
                isBrowserMembershipContextRequiredFailure(
                    failure,
                ),
            ).toBe(true);

            expect(
                isBrowserSessionAuthenticationRequiredFailure(
                    failure,
                ),
            ).toBe(false);
        });

        it('does not classify matching codes with the wrong HTTP status', () => {
            const failure:
                BrowserApiFailure = {
                    ok: false,
                    kind: 'response',
                    status: 403,
                    error: {
                        status:
                            'error',
                        code:
                            'BROWSER_SESSION_AUTHENTICATION_REQUIRED',
                        message:
                            'Authenticated browser session is required.',
                    },
                };

            expect(
                isBrowserSessionAuthenticationRequiredFailure(
                    failure,
                ),
            ).toBe(false);
        });

        it('does not classify network failures as authentication truth', () => {
            const failure:
                BrowserApiFailure = {
                    ok: false,
                    kind: 'network',
                    cause:
                        new TypeError(
                            'Network unavailable',
                        ),
                };

            expect(
                isBrowserSessionAuthenticationRequiredFailure(
                    failure,
                ),
            ).toBe(false);

            expect(
                isBrowserMembershipContextRequiredFailure(
                    failure,
                ),
            ).toBe(false);
        });
    },
);
