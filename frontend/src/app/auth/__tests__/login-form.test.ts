import {
    describe,
    expect,
    it,
} from 'vitest';

import {
    validateLoginForm,
} from '@/app/auth/login-form';

const validValues = {
    email:
        'member@example.com',

    password:
        'correct horse battery staple',

    tenantUuid:
        '018f3b6a-7c20-7cde-8def-1234567890ab',
};

describe(
    'Login form input validation',
    () => {
        it('creates the canonical Browser login request from valid input', () => {
            expect(
                validateLoginForm(
                    validValues,
                ),
            ).toEqual({
                ok:
                    true,

                request: {
                    email:
                        'member@example.com',

                    password:
                        'correct horse battery staple',

                    tenant_uuid:
                        '018f3b6a-7c20-7cde-8def-1234567890ab',
                },
            });
        });



        it('normalizes email and Tenant UUID without modifying the password', () => {
            expect(
                validateLoginForm({
                    email:
                        '  MEMBER@EXAMPLE.COM  ',

                    password:
                        '  secret value  ',

                    tenantUuid:
                        '  018f3b6a-7c20-7cde-8def-1234567890ab  ',
                }),
            ).toEqual({
                ok:
                    true,

                request: {
                    email:
                        'member@example.com',

                    password:
                        '  secret value  ',

                    tenant_uuid:
                        '018f3b6a-7c20-7cde-8def-1234567890ab',
                },
            });
        });

        it('requires an email', () => {
            expect(
                validateLoginForm({
                    ...validValues,

                    email:
                        '   ',
                }),
            ).toEqual({
                ok:
                    false,

                errors: {
                    email:
                        'Email wajib diisi.',
                },
            });
        });

        it('rejects an invalid email format', () => {
            expect(
                validateLoginForm({
                    ...validValues,

                    email:
                        'not-an-email',
                }),
            ).toEqual({
                ok:
                    false,

                errors: {
                    email:
                        'Format email tidak valid.',
                },
            });
        });

        it('requires a password without trimming credential input', () => {
            expect(
                validateLoginForm({
                    ...validValues,

                    password:
                        '',
                }),
            ).toEqual({
                ok:
                    false,

                errors: {
                    password:
                        'Password wajib diisi.',
                },
            });

            const whitespacePassword =
                validateLoginForm({
                    ...validValues,

                    password:
                        '   ',
                });

            expect(
                whitespacePassword,
            ).toEqual({
                ok:
                    true,

                request: {
                    email:
                        validValues.email,

                    password:
                        '   ',

                    tenant_uuid:
                        validValues.tenantUuid,
                },
            });
        });

        it('requires a Tenant UUID', () => {
            expect(
                validateLoginForm({
                    ...validValues,

                    tenantUuid:
                        '   ',
                }),
            ).toEqual({
                ok:
                    false,

                errors: {
                    tenantUuid:
                        'Tenant UUID wajib diisi.',
                },
            });
        });

        it('rejects a malformed Tenant UUID without requiring one UUID version', () => {
            expect(
                validateLoginForm({
                    ...validValues,

                    tenantUuid:
                        'not-a-uuid',
                }),
            ).toEqual({
                ok:
                    false,

                errors: {
                    tenantUuid:
                        'Tenant UUID tidak valid.',
                },
            });

            expect(
                validateLoginForm({
                    ...validValues,

                    tenantUuid:
                        '018f3b6a-7c20-7cde-8def-1234567890ab',
                }).ok,
            ).toBe(
                true,
            );
        });

        it('returns all independently detectable field errors together', () => {
            expect(
                validateLoginForm({
                    email:
                        '',

                    password:
                        '',

                    tenantUuid:
                        'invalid',
                }),
            ).toEqual({
                ok:
                    false,

                errors: {
                    email:
                        'Email wajib diisi.',

                    password:
                        'Password wajib diisi.',

                    tenantUuid:
                        'Tenant UUID tidak valid.',
                },
            });
        });


    },
);
