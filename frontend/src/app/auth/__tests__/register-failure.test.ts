import {
    describe,
    expect,
    it,
} from 'vitest';

import {
    presentRegisterFailure,
} from '@/app/auth/register-failure';
import type {
    BrowserApiFailure,
} from '@/platform/api';

describe(
    'Register failure presentation',
    () => {
        it('returns no presentation when there is no failure', () => {
            expect(
                presentRegisterFailure(
                    null,
                ),
            ).toBeNull();
        });

        it('maps canonical subdomain and admin_email validation fields', () => {
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
                        subdomain: [
                            'The subdomain has already been registered.',
                        ],
                        admin_email: [
                            'This email has already been registered.',
                        ],
                    },
                },
            };

            expect(
                presentRegisterFailure(
                    failure,
                ),
            ).toEqual({
                kind:
                    'validation',
                message:
                    'Periksa kembali data yang ditandai.',
                fieldErrors: {
                    subdomain:
                        'Subdomain tidak dapat diterima. Kemungkinan sudah dipakai institusi lain.',
                    adminEmail:
                        'Email tidak dapat diterima. Kemungkinan sudah terdaftar.',
                },
            });
        });

        it('maps all five canonical registration fields independently', () => {
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
                        name: [
                            'Raw name validation detail.',
                        ],
                        subdomain: [
                            'Raw subdomain validation detail.',
                        ],
                        admin_name: [
                            'Raw admin_name validation detail.',
                        ],
                        admin_email: [
                            'Raw admin_email validation detail.',
                        ],
                        admin_password: [
                            'Raw admin_password validation detail.',
                        ],
                    },
                },
            };

            expect(
                presentRegisterFailure(
                    failure,
                ),
            ).toEqual({
                kind:
                    'validation',
                message:
                    'Periksa kembali data yang ditandai.',
                fieldErrors: {
                    name:
                        'Nama sekolah/institusi tidak dapat diterima.',
                    subdomain:
                        'Subdomain tidak dapat diterima. Kemungkinan sudah dipakai institusi lain.',
                    adminName:
                        'Nama Anda tidak dapat diterima.',
                    adminEmail:
                        'Email tidak dapat diterima. Kemungkinan sudah terdaftar.',
                    adminPassword:
                        'Password tidak dapat diterima.',
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
                        subdomain: [
                            'Sensitive raw server field detail.',
                        ],
                    },
                },
            };

            expect(
                JSON.stringify(
                    presentRegisterFailure(
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
                        future_register_field: [
                            'Future backend rule.',
                        ],
                    },
                },
            };

            expect(
                presentRegisterFailure(
                    failure,
                ),
            ).toEqual({
                kind:
                    'validation',
                message:
                    'Data pendaftaran ditolak oleh server. Periksa kembali data Anda.',
                fieldErrors: {},
            });
        });

        it('presents Browser Session unavailability with registration-specific wording, since the Tenant was already created', () => {
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
                presentRegisterFailure(
                    failure,
                ),
            ).toEqual({
                kind:
                    'service-unavailable',
                message:
                    'Sekolah Anda berhasil didaftarkan, tapi kami tidak dapat langsung memasukkan Anda. Silakan masuk secara manual.',
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
                presentRegisterFailure(
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

        it('suppresses aborted registration lifecycle outcomes', () => {
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
                presentRegisterFailure(
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
                presentRegisterFailure(
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
