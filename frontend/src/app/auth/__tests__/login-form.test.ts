import {
    describe,
    expect,
    it,
} from 'vitest';

import {
    validateLoginForm,
} from '@/app/auth/login-form';

const validValues = {
    identifier:
        'member@example.com',

    password:
        'correct horse battery staple',
};

describe(
    'Login form input validation',
    () => {
        it('creates the canonical global Browser login request', () => {
            expect(
                validateLoginForm(
                    validValues,
                ),
            ).toEqual({
                ok:
                    true,

                request: {
                    identifier:
                        'member@example.com',

                    password:
                        'correct horse battery staple',
                },
            });
        });

        it('trims only surrounding identifier whitespace without modifying password', () => {
            expect(
                validateLoginForm({
                    identifier:
                        '  MEMBER@EXAMPLE.COM  ',

                    password:
                        '  secret value  ',
                }),
            ).toEqual({
                ok:
                    true,

                request: {
                    identifier:
                        'MEMBER@EXAMPLE.COM',

                    password:
                        '  secret value  ',
                },
            });
        });

        it('accepts username identifiers without requiring email syntax', () => {
            expect(
                validateLoginForm({
                    ...validValues,

                    identifier:
                        'school.admin',
                }),
            ).toEqual({
                ok:
                    true,

                request: {
                    identifier:
                        'school.admin',

                    password:
                        validValues.password,
                },
            });
        });

        it('requires an identifier', () => {
            expect(
                validateLoginForm({
                    ...validValues,

                    identifier:
                        '   ',
                }),
            ).toEqual({
                ok:
                    false,

                errors: {
                    identifier:
                        'Identifier wajib diisi.',
                },
            });
        });

        it('requires password without trimming credential input', () => {
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

            expect(
                validateLoginForm({
                    ...validValues,

                    password:
                        '   ',
                }),
            ).toEqual({
                ok:
                    true,

                request: {
                    identifier:
                        validValues.identifier,

                    password:
                        '   ',
                },
            });
        });

        it('returns independently detectable field errors together', () => {
            expect(
                validateLoginForm({
                    identifier:
                        '',

                    password:
                        '',
                }),
            ).toEqual({
                ok:
                    false,

                errors: {
                    identifier:
                        'Identifier wajib diisi.',

                    password:
                        'Password wajib diisi.',
                },
            });
        });
    },
);
