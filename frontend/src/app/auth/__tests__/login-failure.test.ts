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

                it('suppresses the expected anonymous Browser Session bootstrap response', () => {
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
                        'A Browser Session is required.',
                },
            };

            expect(
                presentLoginFailure(
                    failure,
                ),
            ).toBeNull();
        });

        it('presents authentication failure without exposing backend prose', () => {
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
                    'Email, password, atau Tenant UUID tidak cocok.',

                fieldErrors: {},
            });

            expect(
                presentation?.message,
            ).not.toContain(
                'Sensitive backend',
            );
        });

        it('maps known canonical validation fields into frontend field names', () => {
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
                        email: [
                            'Raw email validation detail.',
                        ],

                        password: [
                            'Raw password validation detail.',
                        ],

                        tenant_uuid: [
                            'Raw Tenant validation detail.',
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
                    email:
                        'Email tidak dapat diterima. Periksa kembali email Anda.',

                    password:
                        'Password tidak dapat diterima. Periksa kembali password Anda.',

                    tenantUuid:
                        'Tenant UUID tidak dapat diterima. Periksa kembali Tenant UUID Anda.',
                },
            });
        });

        it('does not forward raw validation messages into presentation', () => {
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
                        email: [
                            'Sensitive raw server field detail.',
                        ],
                    },
                },
            };

            const presentation =
                presentLoginFailure(
                    failure,
                );

            expect(
                JSON.stringify(
                    presentation,
                ),
            ).not.toContain(
                'Sensitive raw server field detail.',
            );
        });

        it('fails safely when canonical validation contains an unknown field', () => {
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

        it('presents Browser Session unavailability as a controlled service failure', () => {
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

        it('presents network failure without exposing its cause', () => {
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
                presentation,
            ).toEqual({
                kind:
                    'network',

                message:
                    'Tidak dapat terhubung ke EduCore. Periksa koneksi Anda lalu coba lagi.',

                fieldErrors: {},
            });

            expect(
                JSON.stringify(
                    presentation,
                ),
            ).not.toContain(
                'Sensitive transport detail.',
            );
        });

        it('presents protocol failure as a controlled unexpected response', () => {
            const failure:
                BrowserApiFailure = {
                ok:
                    false,

                kind:
                    'protocol',

                status:
                    502,

                message:
                    'EduCore API returned an unexpected error response.',
            };

            expect(
                presentLoginFailure(
                    failure,
                ),
            ).toEqual({
                kind:
                    'unexpected',

                message:
                    'EduCore menerima respons yang tidak dapat diproses. Silakan coba lagi.',

                fieldErrors: {},
            });
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

        it('fails closed for an unexpected canonical response code', () => {
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
                presentation,
            ).toEqual({
                kind:
                    'unexpected',

                message:
                    'Permintaan masuk tidak dapat diproses. Silakan coba lagi.',

                fieldErrors: {},
            });

            expect(
                presentation?.message,
            ).not.toContain(
                'Sensitive internal',
            );
        });
    },
);
