import {
    describe,
    expect,
    it,
} from 'vitest';

import {
    presentLoginFailure,
} from '@/app/auth/login-failure';
import type {
    BrowserApiFailure,
} from '@/platform/api';

describe(
    'Login failure presentation',
    () => {
        it('returns no presentation when there is no failure', () => {
            expect(
                presentLoginFailure(
                    null,
                ),
            ).toBeNull();
        });

        it('suppresses expected anonymous Browser Session bootstrap response', () => {
            const failure:
                BrowserApiFailure = {
                ok:
                    false,
                kind:
                    'response',
                status:
                    401,
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
                presentLoginFailure(
                    failure,
                ),
            ).toBeNull();
        });

        it('presents generic authentication failure without Tenant coupling or backend prose', () => {
            const failure:
                BrowserApiFailure = {
                ok:
                    false,
                kind:
                    'response',
                status:
                    401,
                error: {
                    status:
                        'error',
                    code:
                        'AUTHENTICATION_FAILED',
                    message:
                        'Sensitive backend authentication detail.',
                },
            };

            const presentation =
                presentLoginFailure(
                    failure,
                );

            expect(
                presentation,
            ).toEqual({
                kind:
                    'invalid-credentials',
                message:
                    'Identifier atau password tidak cocok.',
                fieldErrors: {},
            });

            expect(
                presentation?.message,
            ).not.toContain(
                'Sensitive backend',
            );

            expect(
                presentation?.message,
            ).not.toContain(
                'Tenant',
            );
        });

        it('maps canonical identifier and password validation fields', () => {
            const failure:
                BrowserApiFailure = {
                ok:
                    false,
                kind:
                    'response',
                status:
                    422,
                error: {
                    status:
                        'error',
                    code:
                        'VALIDATION_FAILED',
                    message:
                        'The submitted data is invalid.',
                    errors: {
                        identifier: [
                            'Raw identifier validation detail.',
                        ],
                        password: [
                            'Raw password validation detail.',
                        ],
                    },
                },
            };

            expect(
                presentLoginFailure(
                    failure,
                ),
            ).toEqual({
                kind:
                    'validation',
                message:
                    'Periksa kembali data login yang ditandai.',
                fieldErrors: {
                    identifier:
                        'Identifier tidak dapat diterima. Periksa kembali email atau username Anda.',
                    password:
                        'Password tidak dapat diterima. Periksa kembali password Anda.',
                },
            });
        });

        it('does not forward raw validation messages', () => {
            const failure:
                BrowserApiFailure = {
                ok:
                    false,
                kind:
                    'response',
                status:
                    422,
                error: {
                    status:
                        'error',
                    code:
                        'VALIDATION_FAILED',
                    message:
                        'The submitted data is invalid.',
                    errors: {
                        identifier: [
                            'Sensitive raw server field detail.',
                        ],
                    },
                },
            };

            expect(
                JSON.stringify(
                    presentLoginFailure(
                        failure,
                    ),
                ),
            ).not.toContain(
                'Sensitive raw server field detail.',
            );
        });

        it('fails safely for unknown validation fields', () => {
            const failure:
                BrowserApiFailure = {
                ok:
                    false,
                kind:
                    'response',
                status:
                    422,
                error: {
                    status:
                        'error',
                    code:
                        'VALIDATION_FAILED',
                    message:
                        'The submitted data is invalid.',
                    errors: {
                        future_login_field: [
                            'Future backend rule.',
                        ],
                    },
                },
            };

            expect(
                presentLoginFailure(
                    failure,
                ),
            ).toEqual({
                kind:
                    'validation',
                message:
                    'Data login ditolak oleh server. Periksa kembali data Anda.',
                fieldErrors: {},
            });
        });

        it('presents Browser Session unavailability as controlled service failure', () => {
            const failure:
                BrowserApiFailure = {
                ok:
                    false,
                kind:
                    'response',
                status:
                    503,
                error: {
                    status:
                        'error',
                    code:
                        'BROWSER_SESSION_UNAVAILABLE',
                    message:
                        'Internal session custody detail.',
                },
            };

            expect(
                presentLoginFailure(
                    failure,
                ),
            ).toEqual({
                kind:
                    'service-unavailable',
                message:
                    'Layanan sesi EduCore sedang tidak tersedia. Silakan coba lagi.',
                fieldErrors: {},
            });
        });

        it('presents network failure without exposing cause', () => {
            const failure:
                BrowserApiFailure = {
                ok:
                    false,
                kind:
                    'network',
                cause:
                    new Error(
                        'Sensitive transport detail.',
                    ),
            };

            const presentation =
                presentLoginFailure(
                    failure,
                );

            expect(
                presentation?.kind,
            ).toBe(
                'network',
            );

            expect(
                JSON.stringify(
                    presentation,
                ),
            ).not.toContain(
                'Sensitive transport detail.',
            );
        });

        it('suppresses aborted login lifecycle outcomes', () => {
            const failure:
                BrowserApiFailure = {
                ok:
                    false,
                kind:
                    'aborted',
                cause:
                    new DOMException(
                        'Aborted',
                        'AbortError',
                    ),
            };

            expect(
                presentLoginFailure(
                    failure,
                ),
            ).toBeNull();
        });

        it('fails closed for unexpected canonical response code', () => {
            const failure:
                BrowserApiFailure = {
                ok:
                    false,
                kind:
                    'response',
                status:
                    500,
                error: {
                    status:
                        'error',
                    code:
                        'INTERNAL_SERVER_ERROR',
                    message:
                        'Sensitive internal backend detail.',
                },
            };

            const presentation =
                presentLoginFailure(
                    failure,
                );

            expect(
                presentation?.kind,
            ).toBe(
                'unexpected',
            );

            expect(
                JSON.stringify(
                    presentation,
                ),
            ).not.toContain(
                'Sensitive internal',
            );
        });
    },
);
